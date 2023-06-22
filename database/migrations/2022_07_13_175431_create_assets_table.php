<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAssetsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('code')->nullable();
            $table->string('name');
            $table->string('alternative_name')->nullable();
            $table->string('group')->nullable();
            $table->string('serial_no')->nullable();
            $table->integer('quantity')->default(0);
            $table->string('photo')->nullable();
            $table->string('details')->nullable();
            $table->timestamp('warranty_end')->nullable();
            $table->boolean('summarize_by_group')->default(false);
            $table->enum('status', ['active', 'repaired', 'disposed'])->default('active');
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('restrict')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('assets');
    }
}
