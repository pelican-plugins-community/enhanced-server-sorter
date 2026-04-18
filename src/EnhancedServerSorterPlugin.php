<?php

namespace Olivier\EnhancedServerSorter;

use App\Contracts\Plugins\HasPluginSettings;
use App\Enums\HeaderActionPosition;
use App\Filament\App\Resources\Servers\Pages\ListServers;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Panel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Olivier\EnhancedServerSorter\Models\DefaultFolder;
use Olivier\EnhancedServerSorter\Models\DefaultFolderServerAssignment;
use Olivier\EnhancedServerSorter\Models\ServerFolder;
use Olivier\EnhancedServerSorter\Models\ServerFolderAssignment;
use Olivier\EnhancedServerSorter\Providers\EnhancedServerSorterServiceProvider;

class EnhancedServerSorterPlugin implements HasPluginSettings, Plugin
{
    private static bool $providerRegistered = false;

    public function getId(): string
    {
        return 'enhanced-server-sorter';
    }

    public function register(Panel $panel): void
    {
        if ($panel->getId() !== 'app') {
            return;
        }

        ListServers::registerCustomHeaderActions(
            HeaderActionPosition::After,
            $this->makeManageFoldersAction()
        );
    }

    public function boot(Panel $panel): void
    {
        //
    }

    public function getServiceProviders(): array
    {
        return [
            EnhancedServerSorterServiceProvider::class,
        ];
    }

    private function makeManageFoldersAction(): Action
    {
        return Action::make('manageFolders')
            ->label(fn () => trans('enhanced-server-sorter::messages.manage_folders'))
            ->icon('tabler-folders')
            ->modalWidth('2xl')
            ->modalSubmitActionLabel(fn () => trans('enhanced-server-sorter::messages.save'))
            ->modalCancelActionLabel(fn () => trans('enhanced-server-sorter::messages.cancel'))
            ->visible(fn () => user() !== null)
            ->form(function () {
                if (!Schema::hasTable('enhanced_server_folders')) {
                    return [
                        \Filament\Forms\Components\Placeholder::make('migration_required')
                            ->label(fn () => trans('enhanced-server-sorter::messages.migration_required'))
                            ->content(fn () => trans('enhanced-server-sorter::messages.migration_required_message'))
                    ];
                }

                $servers = user()?->accessibleServers()?->pluck('name', 'id') ?? collect();

                return [
                    Repeater::make('folders')
                        ->label(fn () => trans('enhanced-server-sorter::messages.folders'))
                        ->default(fn () => $this->loadFolders())
                        ->live()
                        ->schema([
                            Hidden::make('id'),
                            TextInput::make('name')
                                ->label(fn () => trans('enhanced-server-sorter::messages.folder_name'))
                                ->required()
                                ->maxLength(255),
                            Select::make('server_ids')
                                ->label(fn () => trans('enhanced-server-sorter::messages.servers'))
                                ->multiple()
                                ->options($servers)
                                ->searchable()
                                ->preload()
                                ->live()
                                ->disabled(fn ($get) => $this->isFolderLocked($get('name')))
                                ->helperText(fn ($get) => $this->isFolderLocked($get('name')) 
                                    ? trans('enhanced-server-sorter::messages.folder_locked') 
                                    : null)
                                ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                        ])
                        ->collapsed()
                        ->reorderableWithButtons()
                        ->addActionLabel(fn () => trans('enhanced-server-sorter::messages.add_folder')),
                ];
            })
            ->action(function (array $data) {
                $user = user();

                if (!$user) {
                    return;
                }

                $folders = collect($data['folders'] ?? []);
                
                $allServerIds = [];
                $duplicates = [];
                
                foreach ($folders as $folder) {
                    $serverIds = $folder['server_ids'] ?? [];
                    foreach ($serverIds as $serverId) {
                        if (in_array($serverId, $allServerIds)) {
                            $duplicates[] = $serverId;
                        }
                        $allServerIds[] = $serverId;
                    }
                }
                
                if (!empty($duplicates)) {
                    $serverNames = user()?->accessibleServers()
                        ->whereIn('servers.id', array_unique($duplicates))
                        ->pluck('name')
                        ->join(', ');
                    
                    Notification::make()
                        ->title(trans('enhanced-server-sorter::messages.duplicate_servers'))
                        ->body(trans('enhanced-server-sorter::messages.duplicate_servers_message', ['servers' => $serverNames]))
                        ->danger()
                        ->send();
                    
                    return;
                }

                $this->persistFolders($folders, $user->id);

                Notification::make()
                    ->title(trans('enhanced-server-sorter::messages.folders_updated'))
                    ->success()
                    ->send();
            });
    }

    private function loadFolders(): array
    {
        $user = user();

        if (!$user) {
            return [];
        }

        if (!Schema::hasTable('enhanced_server_folders')) {
            return [];
        }

        try {
            $this->syncDefaultFoldersForUser($user->id);
            $lockedFolderNames = $this->getLockedFolderNames();

            return ServerFolder::query()
                ->with('assignments')
                ->where('user_id', $user->id)
                ->orderBy('sort')
                ->get()
                ->map(fn (ServerFolder $folder) => [
                    'id' => $folder->id,
                    'name' => $folder->name,
                    'server_ids' => $folder->assignments->pluck('server_id')->all(),
                    'is_locked' => in_array($folder->name, $lockedFolderNames),
                ])
                ->all();
        } catch (\Exception $e) {
            return [];
        }
    }

    private function persistFolders(Collection $folders, int $userId): void
    {
        if (!Schema::hasTable('enhanced_server_folders')) {
            return;
        }

        $accessibleServerIds = user()?->accessibleServers()?->pluck('id')->all() ?? [];
        $existingIds = [];
        $lockedFolderNames = $this->getLockedFolderNames();

        foreach ($folders as $index => $folderData) {
            $folder = isset($folderData['id'])
                ? ServerFolder::query()
                    ->where('user_id', $userId)
                    ->whereKey($folderData['id'])
                    ->first()
                : null;

            if (!$folder) {
                $folder = new ServerFolder(['user_id' => $userId]);
            }

            $folderName = $folderData['name'] ?? 'Folder';
            $isLocked = in_array($folderName, $lockedFolderNames);

            $folder->name = $folderName;
            $folder->sort = ($index * 10);
            $folder->save();

            $existingIds[] = $folder->id;

            if ($isLocked) {
                $defaultFolder = DefaultFolder::query()
                    ->where('name', $folderName)
                    ->where('is_locked', true)
                    ->with('serverAssignments')
                    ->first();

                if ($defaultFolder) {
                    $serverIds = $defaultFolder->serverAssignments
                        ->sortBy('position')
                        ->pluck('server_id')
                        ->intersect($accessibleServerIds)
                        ->values()
                        ->all();
                } else {
                    $serverIds = [];
                }
            } else {
                $serverIds = collect($folderData['server_ids'] ?? [])
                    ->unique()
                    ->intersect($accessibleServerIds)
                    ->values()
                    ->all();
            }

            $this->syncAssignments($folder, $serverIds, $userId);
        }

        ServerFolder::query()
            ->where('user_id', $userId)
            ->whereNotIn('id', $existingIds)
            ->whereNotIn('name', $lockedFolderNames)
            ->each(fn (ServerFolder $folder) => $folder->delete());
    }

    private function isFolderLocked(?string $folderName): bool
    {
        if (!$folderName) {
            return false;
        }

        $lockedFolderNames = $this->getLockedFolderNames();
        return in_array($folderName, $lockedFolderNames);
    }

    private function getLockedFolderNames(): array
    {
        try {
            if (!Schema::hasTable('enhanced_server_default_folders')) {
                return [];
            }

            return DefaultFolder::query()
                ->where('is_locked', true)
                ->pluck('name')
                ->all();
        } catch (\Exception $e) {
            return [];
        }
    }

    private function syncAssignments(ServerFolder $folder, array $serverIds, int $userId): void
    {
        if (!Schema::hasTable('enhanced_server_folder_server')) {
            return;
        }

        ServerFolderAssignment::query()
            ->where('folder_id', $folder->id)
            ->where('user_id', $userId)
            ->delete();

        if (empty($serverIds)) {
            return;
        }

        $rows = [];

        foreach ($serverIds as $position => $serverId) {
            $rows[] = [
                'folder_id' => $folder->id,
                'server_id' => $serverId,
                'user_id' => $userId,
                'position' => $position,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        ServerFolderAssignment::query()->insert($rows);
    }

    public function getSettingsForm(): array
    {
        try {
            $servers = Server::query()->pluck('name', 'id')->all();
        } catch (\Exception $e) {
            $servers = [];
        }

        return [
            Repeater::make('default_folders')
                ->label(fn () => trans('enhanced-server-sorter::messages.default_folders'))
                ->helperText(fn () => trans('enhanced-server-sorter::messages.default_folders_help'))
                ->default(fn () => $this->loadDefaultFolders())
                ->schema([
                    Hidden::make('id'),
                    TextInput::make('name')
                        ->label(fn () => trans('enhanced-server-sorter::messages.folder_name'))
                        ->required()
                        ->maxLength(255),
                    Select::make('server_ids')
                        ->label(fn () => trans('enhanced-server-sorter::messages.servers'))
                        ->multiple()
                        ->options($servers)
                        ->searchable()
                        ->preload()
                        ->helperText(fn () => trans('enhanced-server-sorter::messages.select_servers_help'))
                        ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                    \Filament\Forms\Components\Toggle::make('is_locked')
                        ->label(fn () => trans('enhanced-server-sorter::messages.lock_folder'))
                        ->helperText(fn () => trans('enhanced-server-sorter::messages.lock_folder_help'))
                        ->default(false)
                        ->inline(false),
                ])
                ->collapsible()
                ->collapsed()
                ->reorderableWithButtons()
                ->addActionLabel(fn () => trans('enhanced-server-sorter::messages.add_default_folder'))
                ->columns(2),
        ];
    }

    protected function loadDefaultFolders(): array
    {
        try {
            if (!Schema::hasTable('enhanced_server_default_folders')) {
                return [];
            }

            return DefaultFolder::query()
                ->with('serverAssignments')
                ->orderBy('sort')
                ->get()
                ->map(fn (DefaultFolder $folder) => [
                    'id' => $folder->id,
                    'name' => $folder->name,
                    'server_ids' => $folder->serverAssignments->pluck('server_id')->all(),
                    'is_locked' => $folder->is_locked ?? false,
                ])
                ->all();
        } catch (\Exception $e) {
            return [];
        }
    }

    public function saveSettings(array $data): void
    {
        try {
            if (Schema::hasTable('enhanced_server_default_folder_servers')) {
                DefaultFolderServerAssignment::query()->delete();
            }
            if (Schema::hasTable('enhanced_server_default_folders')) {
                DefaultFolder::query()->delete();
            }

            $folders = collect($data['default_folders'] ?? []);

            foreach ($folders as $index => $folderData) {
                $folder = new DefaultFolder();
                $folder->name = $folderData['name'] ?? 'Folder';
                $folder->sort = ($index * 10);
                $folder->is_locked = $folderData['is_locked'] ?? false;
                $folder->save();

                $serverIds = collect($folderData['server_ids'] ?? [])
                    ->unique()
                    ->values()
                    ->all();

                if (!empty($serverIds)) {
                    $rows = [];
                    foreach ($serverIds as $position => $serverId) {
                        $rows[] = [
                            'default_folder_id' => $folder->id,
                            'server_id' => $serverId,
                            'position' => $position,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                    DefaultFolderServerAssignment::query()->insert($rows);
                }
            }

            $this->syncDefaultFoldersForAllUsers();

            Notification::make()
                ->title(trans('enhanced-server-sorter::messages.default_folders_saved'))
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title(trans('enhanced-server-sorter::messages.error_saving'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function syncDefaultFoldersForUser(int $userId): void
    {
        try {
            if (!Schema::hasTable('enhanced_server_default_folders') || !Schema::hasTable('enhanced_server_folders')) {
                return;
            }

            $defaultFolders = DefaultFolder::query()
                ->with('serverAssignments')
                ->orderBy('sort')
                ->get();

            foreach ($defaultFolders as $index => $defaultFolder) {
                $folder = ServerFolder::query()
                    ->where('user_id', $userId)
                    ->where('name', $defaultFolder->name)
                    ->first();

                if (!$folder) {
                    $folder = new ServerFolder([
                        'user_id' => $userId,
                        'name' => $defaultFolder->name,
                        'sort' => $defaultFolder->sort,
                    ]);
                    $folder->save();
                } else {
                    $folder->sort = $defaultFolder->sort;
                    $folder->save();
                }

                $serverIds = $defaultFolder->serverAssignments
                    ->sortBy('position')
                    ->pluck('server_id')
                    ->all();

                if (!empty($serverIds)) {
                    ServerFolderAssignment::query()
                        ->where('folder_id', $folder->id)
                        ->where('user_id', $userId)
                        ->delete();

                    $rows = [];
                    foreach ($serverIds as $position => $serverId) {
                        $rows[] = [
                            'folder_id' => $folder->id,
                            'server_id' => $serverId,
                            'user_id' => $userId,
                            'position' => $position,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                    ServerFolderAssignment::query()->insert($rows);
                }
            }
        } catch (\Exception $e) {
        }
    }

    public function syncDefaultFoldersForAllUsers(): void
    {
        try {
            if (!Schema::hasTable('enhanced_server_default_folders')) {
                return;
            }

            $userModelClass = config('panel.auth.models.user', \App\Models\User::class);
            
            if (!class_exists($userModelClass)) {
                return;
            }

            $users = $userModelClass::query()->get();

            foreach ($users as $user) {
                $this->syncDefaultFoldersForUser($user->id);
            }
        } catch (\Exception $e) {
        }
    }

}
