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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('reference');

            //Les clés étrangères reliées à la table users (Nullables en cas de depot/retrait)
            $table->foreignId('expediteur_id')->nullable()->constrained('users');
            $table->foreignId('destinataire_id')->nullable()->constrained('users');

            $table->decimal('montant', 15, 2);
            $table->decimal('frais', 10, 2)->default(0.00);
            $table->enum('type', ['transfert', 'reception', 'depôt', 'retrait']);
            $table->enum('statut', ['en_attente', 'complete', 'echoue'])->default('en_attente');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
