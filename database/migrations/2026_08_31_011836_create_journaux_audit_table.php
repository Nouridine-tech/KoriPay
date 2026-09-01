<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journaux_audit', function (Blueprint $table) {
            $table->id();

            // L'utilisateur (admin ou agent) qui a exécuté l'action
            $table->foreignId('acteur_id')->constrained('users');

            // Le type d'action effectuée (ex: 'suspension_compte', 'annulation_transaction')
            $table->string('action');

            // Colonnes polymorphes 'cible_type' et 'cible_id' : permettent de pointer
            // vers n'importe quelle table (User pour un compte, Transaction pour une transaction)
            $table->nullableMorphs('cible');

            // Raison donnée par l'acteur pour justifier l'action, si applicable
            $table->text('motif')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journaux_audit');
    }
};
