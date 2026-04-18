<?php

namespace Olivier\EnhancedServerSorter\Providers;

use App\Enums\CustomizationKey;
use App\Filament\App\Resources\Servers\Pages\ListServers as AppListServers;
use App\Models\Server;
use Filament\Tables\Columns\Column;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class EnhancedServerSorterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Server::resolveRelationUsing('enhancedFolderAssignment', function (Server $server) {
            return $server->hasOne(\Olivier\EnhancedServerSorter\Models\ServerFolderAssignment::class, 'server_id', 'id')
                ->where('user_id', user()?->id)
                ->with('folder');
        });
    }

    public function boot(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('enhanced_server_folder_server')) {
            return;
        }

        Table::configureUsing(function (Table $table): void {
            $component = $table->getLivewire();

            if (!$component instanceof AppListServers) {
                return;
            }

            $usingGrid = user()?->getCustomization(CustomizationKey::DashboardLayout) === 'grid';

            $table
                ->groups([
                    (function (): Group {
                        $group = Group::make('enhancedFolderAssignment.folder.name')
                            ->label(fn () => trans('enhanced-server-sorter::messages.folders'))
                            ->titlePrefixedWithLabel(false);

                        $group->getTitleFromRecordUsing(function (Server $server): string {
                            return $server->enhancedFolderAssignment?->folder?->name ?? trans('enhanced-server-sorter::messages.unassigned');
                        })
                        ->collapsible();

                        $group->orderQueryUsing(function (Builder $query, string $direction): Builder {
                            $userId = (int) (user()?->id ?? 0);
                            $serverIdColumn = Server::query()->qualifyColumn('id');
                            $folderSortSub = "(SELECT esf.sort FROM enhanced_server_folder_server esfs JOIN enhanced_server_folders esf ON esf.id = esfs.folder_id WHERE esfs.user_id = {$userId} AND esfs.server_id = {$serverIdColumn} LIMIT 1)";
                            $positionSub = "(SELECT esfs.position FROM enhanced_server_folder_server esfs WHERE esfs.user_id = {$userId} AND esfs.server_id = {$serverIdColumn} LIMIT 1)";

                            $directionSql = $direction === 'desc' ? 'DESC' : 'ASC';

                            return $query
                                ->orderByRaw("COALESCE({$folderSortSub}, 2147483647) {$directionSql}")
                                ->orderByRaw("COALESCE({$positionSub}, 2147483647) {$directionSql}");
                        });

                        return $group;
                    })(),
                ])
                ->defaultGroup('enhancedFolderAssignment.folder.name');
        });
    }
}
