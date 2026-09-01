<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tentatives_connexion', function (Blueprint $table) {
            $table->id();
            // Lien vers le compte utilisateur concerné
            // onDelete cascade : si le compte est supprimé, les tentatives le sont aussi
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');

            // Type d'action concernée (login, verification_identite, reinitialisation_pin, nouvel_appareil)
            // Permet d'avoir un compteur indépendant par route, pour un même utilisateur
            $table->string('type_action');

            // Nombre de tentatives échouées consécutives
            $table->integer('tentatives')->default(0);

            // Horodatage de la dernière tentative échouée
            $table->timestamp('derniere_tentative')->nullable();

            //Date et heure de fin de suspension temporaire automatique
            $table->timestamp('suspendu_jusqu_a')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tentatives_connexion');
    }
};
