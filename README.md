# PROJET-UP-PRO

Projet collaboratif à 3.

## Module 1 - ORIENT'ESI

Application Laravel d'orientation des étudiants STIC entre les spécialités EIT et INFO.

Fonctionnalités principales:

- Import du relevé CSV officiel ESI
- Création automatique des comptes étudiants
- Connexion par matricule
- Saisie des vœux par les étudiants
- Consultation des vœux par le RUP
- Estimation EIT/INFO selon les notes UE, les coefficients et les capacités

## Installation

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8001
```
