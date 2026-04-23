[Français](#installation-docker) · [English](#docker-installation)

---

# Installation Docker

## Introduction

_basé sur [Symfony-Docker](https://github.com/dunglas/symfony-docker) recommandé par [Using Docker with Symfony](https://symfony.com/doc/current/setup/docker.html)_

## Prérequis

1. Si ce n'est pas déjà fait, [installer Docker Compose](https://docs.docker.com/compose/install/) (v2.25+)

## Les commandes Docker à connaître

Dans tous les cas n'hésitez pas à regarder dans le Makefile pour vous souvenir des commandes.

### _Pour les utilisateurices Windows :_
- Lancez `docker compose build --pull --no-cache` pour reconstruire l'image à neuf.
- Lancez `docker compose up --wait` pour configurer et démarrer le projet Symfony.
- Lancez `docker compose down --remove-orphans` pour arrêter les conteneurs Docker.
- Pour lancer des commandes dans le conteneur on utilise `docker compose exec php`, exemples :
    - `docker compose exec php bash` permet d'entrer en bash dans le conteneur et ensuite de lancer des commandes comme d'habitude, comme `php bin/console` ou `composer`
    - on peut aussi directement lancer des commandes sans entrer en bash : `docker compose exec php php bin/console`

### _Pour les utilisateurices Linux/Mac (avec la commande `make`) :_
- Lancez `make build` pour reconstruire les images à neuf.
- Lancez `make up` pour configurer et démarrer le projet Symfony.
- Lancez `make down` pour arrêter les conteneurs Docker.
- Pour lancer des commandes, plusieurs méthodes (n'hésitez pas à utiliser `make help` pour lister les commandes disponibles via `make`) :
    - `make bash` pour entrer dans le conteneur en bash et lancer des commandes comme d'habitude
    - `make sh c="php bin/console"`
    - `make sf` pour lancer des commandes Symfony

## Étape par Étape

1. Cloner le dépôt et copier `.env` en `.env.local` en renseignant les variables nécessaires.
2. Lancer la commande de build : _`build`_
3. Démarrer les conteneurs : _`up`_ (la BDD et les migrations vont se créer et se jouer automatiquement)
4. Ouvrez `https://localhost` dans votre navigateur et [acceptez le certificat TLS auto-généré](https://stackoverflow.com/a/15076602/1352334)
5. Pour les utilisateurices de Make, utiliser `make setup` afin de gagner du temps — allez quand même voir l'install classique pour mieux comprendre le setup.
6. Stopper les conteneurs quand vous avez fini de travailler : _`down`_

---

# Docker Installation

## Introduction

_based on [Symfony-Docker](https://github.com/dunglas/symfony-docker) as recommended by [Using Docker with Symfony](https://symfony.com/doc/current/setup/docker.html)_

## Prerequisites

1. If not already done, [install Docker Compose](https://docs.docker.com/compose/install/) (v2.25+)

## Useful Docker Commands

In any case, feel free to check the Makefile to look up available commands.

### _For Windows users:_
- Run `docker compose build --pull --no-cache` to build fresh images.
- Run `docker compose up --wait` to set up and start the Symfony project.
- Run `docker compose down --remove-orphans` to stop the Docker containers.
- To run commands inside the container use `docker compose exec php`, examples:
    - `docker compose exec php bash` to open a bash shell inside the container and run commands as usual, such as `php bin/console` or `composer`
    - you can also run commands directly without opening a shell: `docker compose exec php php bin/console`

### _For Linux/Mac users (with the `make` command):_
- Run `make build` to build fresh images.
- Run `make up` to set up and start the Symfony project.
- Run `make down` to stop the Docker containers.
- To run commands, several options (use `make help` to list all available `make` commands):
    - `make bash` to open a bash shell inside the container and run commands as usual
    - `make sh c="php bin/console"`
    - `make sf` to run Symfony commands

## Step by Step

1. Clone the repository and copy `.env` to `.env.local`, filling in the required variables.
2. Build the images: _`build`_
3. Start the containers: _`up`_ (the database and migrations will be created and run automatically)
4. Open `https://localhost` in your browser and [accept the auto-generated TLS certificate](https://stackoverflow.com/a/15076602/1352334)
6. Stop the containers when you are done: _`down`_

