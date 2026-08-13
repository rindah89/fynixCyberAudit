#!/bin/bash
clear

echo -e "                 \033[34m+++++++++++++++++++\033[96mx≈≈≈≈≈\033[0m"
echo -e "                \033[34m+++++++++++++++++++\033[96m÷≈≈≈≈≈\033[0m"
echo -e "               \033[34m+++++              \033[96m≈≈≈≈≈≈\033[0m"
echo -e "              \033[34m+++++              \033[96m≈≈≈≈≈\033[0m"
echo -e "             \033[34m+++++              \033[96m≈≈≈≈≈\033[0m"
echo -e "            \033[34m+++++              \033[96m≈≈≈≈≈\033[0m"
echo -e "           \033[34m+++++\033[96m-≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈\033[0m"
echo -e "          \033[34m+++++\033[96m≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈\033[0m"
echo -e "         \033[34m+++++\033[96m≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈≈\033[0m"
echo -e "          \033[34m+++++     \033[36m×××××××××××××××××××××\033[0m"
echo -e "           \033[34m+++++   \033[36m×××××××××××××××××××××××\033[0m"
echo -e "            \033[34m+++++ \033[36m×××××               ×××××\033[0m"
echo -e "             \033[34m+++++\033[36m××××              +++××××××\033[0m"
echo -e "              \033[34m+++++-×              \033[36m++++x××××××\033[0m"
echo -e "               \033[34m++++++++++++++++++++++  \033[36mx××××÷\033[0m"
echo -e "                \033[34m+++++++++++++++++++++     \033[36mx××××\033[0m"
echo -e "                 \033[34m+++++++++++++++++++       \033[36m÷÷x××\033[0m"
echo ""
echo ""
echo -e "  \033[36m ██████╗ ██████╗ ███████╗███╗   ██╗ ██████╗ ██████╗  ██████╗"
echo -e "  ██╔═══██╗██╔══██╗██╔════╝████╗  ██║██╔════╝ ██╔══██╗██╔════╝"
echo -e "  ██║   ██║██████╔╝█████╗  ██╔██╗ ██║██║  ███╗██████╔╝██║"
echo -e "  ██║   ██║██╔═══╝ ██╔══╝  ██║╚██╗██║██║   ██║██╔══██╗██║"
echo -e "  ╚██████╔╝██║     ███████╗██║ ╚████║╚██████╔╝██║  ██║╚██████╗"
echo -e "   ╚═════╝ ╚═╝     ╚══════╝╚═╝  ╚═══╝ ╚═════╝ ╚═╝  ╚═╝ ╚═════╝\033[0m"


echo ""
echo -e "################################################################"
echo -e "##                 FYNIX CYBER AUDIT UPDATER                  ##"
echo -e "################################################################"

echo ""
read -p "Press any key to Continue, or Ctrl+C to quit " choice

echo ""
echo -e "################################################################"
echo ""

## Trash dynamic files
git stash

## Pull the latest changes
echo "Pulling the latest changes..."
git pull

## Run Composer
echo "Installing Composer Dependencies..."
composer update

## Migrate the database
echo "Migrating the database..."
php artisan migrate

## Clear Caches
echo "Clear Caches"
php artisan cache:clear

# Check and create storage symlink if needed
echo "Checking storage symlink..."
if [ ! -L "public/storage" ]; then
    echo "Creating storage symlink..."
    php artisan storage:link
else
    echo "Storage symlink already exists"
fi

# Build the Frontend
echo "Installing npm dependencies..."
npm install

echo "Building the frontend..."
npm run build
