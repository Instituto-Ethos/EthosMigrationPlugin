#!/bin/bash

if ! command -v wp &> /dev/null
then
    echo "WP-CLI não está instalado. Por favor, instale o WP-CLI e tente novamente."
    exit 1
fi

echo "Executando comandos WP-CLI...\n"

wp import-accounts

echo "\nFim da execução dos comandos."
