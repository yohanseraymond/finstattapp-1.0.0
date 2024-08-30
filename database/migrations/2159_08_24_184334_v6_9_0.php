<?php

use App\Model\Attachment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class V690 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Resetting breads per latest changes
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        Artisan::call('db:seed',['--force'=>true,'--class'=>'Database\Seeders\DataTypesTableSeeder']);
        Artisan::call('db:seed',['--force'=>true,'--class'=>'Database\Seeders\DataRowsTableSeeder']);
        Artisan::call('db:seed',['--force'=>true,'--class'=>'Database\Seeders\MenusTableSeeder']);
        Artisan::call('db:seed',['--force'=>true,'--class'=>'Database\Seeders\MenuItemsTableSeeder']);
        Artisan::call('db:seed',['--force'=>true,'--class'=>'Database\Seeders\RolesTableSeeder']);
        Artisan::call('db:seed',['--force'=>true,'--class'=>'Database\Seeders\PermissionsTableSeeder']);
        Artisan::call('db:seed',['--force'=>true,'--class'=>'Database\Seeders\PermissionRoleTableSeeder']);
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        Artisan::call('optimize:clear');

        DB::table('settings')->insert(
            array(
                'key' => 'feed.default_search_widget_filter',
                'display_name' => 'Default search widget filter',
                'value' => 'top',
                'details' => '{
"default" : "top",
"options" : {
"live": "Live",
"top": "Top",
"people": "People",
"videos": "Videos",
"photos": "Photos"
}
}',
                'type' => 'select_dropdown',
                'order' => 100,
                'group' => 'Feed',
            )
        );

    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {

        DB::table('settings')
            ->where('key', 'feed.default_search_widget_filter')
            ->delete();
    }
}
