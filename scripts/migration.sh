#!/bin/bash

if ! command -v wp &> /dev/null
then
    echo "WP-CLI não está instalado. Por favor, instale o WP-CLI e tente novamente."
    exit 1
fi

echo "Executando comandos WP-CLI..."

wp modify-posts q:post_type=cedoc q:post_status=draft,private,publish q:tax_query=categoria:documentos fn:ethos\\set_post_type_publicacao
if [ $? -ne 0 ]; then
    echo "Erro ao executar o comando."
    exit 1
fi

wp modify-posts q:post_type=cedoc q:post_status=draft,private,publish q:tax_query=categoria:noticias fn:ethos\\set_post_type_post
if [ $? -ne 0 ]; then
    echo "Erro ao executar o comando."
    exit 1
fi

wp modify-posts q:post_type=cedoc q:post_status=draft,private,publish q:tax_query=categoria:podcast fn:ethos\\set_post_type_post
if [ $? -ne 0 ]; then
    echo "Erro ao executar o comando."
    exit 1
fi

wp modify-posts q:post_type=cedoc q:post_status=draft,private,publish q:tax_query=categoria:publicacoes fn:ethos\\set_post_type_publicacao
if [ $? -ne 0 ]; then
    echo "Erro ao executar o comando."
    exit 1
fi

wp modify-posts q:post_type=cedoc q:post_status=draft,private,publish q:tax_query=categoria:releases fn:ethos\\set_post_type_post
if [ $? -ne 0 ]; then
    echo "Erro ao executar o comando."
    exit 1
fi

wp modify-posts q:post_type=cedoc q:post_status=draft,private,publish q:tax_query=categoria:videos fn:ethos\\set_post_type_post
if [ $? -ne 0 ]; then
    echo "Erro ao executar o comando."
    exit 1
fi

wp modify-posts q:post_type=conteudo q:post_status=draft,private,publish q:tax_query=category:associados fn:ethos\\set_post_type_page
if [ $? -ne 0 ]; then
    echo "Erro ao executar o comando."
    exit 1
fi

wp modify-posts q:post_type=conteudo q:post_status=draft,private,publish q:tax_query=category:temas fn:ethos\\set_post_type_post
if [ $? -ne 0 ]; then
    echo "Erro ao executar o comando."
    exit 1
fi

wp modify-posts q:post_type=conteudo q:post_status=draft,private,publish q:tax_query=category:destaque-cedoc fn:ethos\\set_post_type_iniciativa
if [ $? -ne 0 ]; then
    echo "Erro ao executar o comando."
    exit 1
fi

wp modify-posts q:post_type=conteudo q:post_status=draft,private,publish q:tax_query=category:iniciativas fn:ethos\\set_post_type_iniciativa
if [ $? -ne 0 ]; then
    echo "Erro ao executar o comando."
    exit 1
fi

wp modify-posts q:post_type=conteudo q:post_status=draft,private,publish q:tax_query="category:o-instituto-ethos;post_tag:posicionamento-institucional" fn:ethos\\set_post_type_post
if [ $? -ne 0 ]; then
    echo "Erro ao executar o comando."
    exit 1
fi

wp modify-posts q:post_type=conteudo q:post_status=draft,private,publish q:tax_query="category:o-instituto-ethos;post_tag:opinioes-e-analises" fn:ethos\\set_post_type_page
if [ $? -ne 0 ]; then
    echo "Erro ao executar o comando."
    exit 1
fi

wp modify-posts q:post_type=conteudo q:post_status=draft,private,publish q:tax_query=category:parcerias fn:ethos\\set_post_type_iniciativa
if [ $? -ne 0 ]; then
    echo "Erro ao executar o comando."
    exit 1
fi

wp modify-posts q:post_type=conteudo q:post_status=draft,private,publish q:tax_query=category:rede-ethos fn:ethos\\set_post_type_page
if [ $? -ne 0 ]; then
    echo "Erro ao executar o comando."
    exit 1
fi

wp modify-posts q:post_type=conteudo q:post_status=draft,private,publish q:tax_query=category:sem-categoria fn:ethos\\set_post_type_page
if [ $? -ne 0 ]; then
    echo "Erro ao executar o comando."
    exit 1
fi

wp modify-posts q:post_type=iniciativa,post,publicacao q:post_status=draft,private,publish fn:ethos\\change_tag_to_category
if [ $? -ne 0 ]; then
    echo "Erro ao executar o comando."
    exit 1
fi

wp modify-posts q:post_type=iniciativa,post,publicacao q:post_status=draft,private,publish q:tax_query=post_tag:posicionamento-institucional fn:ethos\\set_posicionamento_institucional
if [ $? -ne 0 ]; then
    echo "Erro ao executar o comando."
    exit 1
fi

wp modify-posts q:post_type=iniciativa,post,publicacao q:post_status=draft,private,publish q:tax_query=post_tag:opinioes-e-analises fn:ethos\\set_opinioes_e_analises
if [ $? -ne 0 ]; then
    echo "Erro ao executar o comando."
    exit 1
fi

wp modify-posts q:post_type=iniciativa,post,publicacao q:post_status=draft,private,publish q:tax_query=categoria:noticias fn:ethos\\set_noticias
if [ $? -ne 0 ]; then
    echo "Erro ao executar o comando."
    exit 1
fi

wp modify-posts q:post_type=post q:post_status=draft,private,publish q:tax_query=categoria:noticias fn:ethos\\set_post_type_post
if [ $? -ne 0 ]; then
    echo "Erro ao executar o comando."
    exit 1
fi

wp modify-posts q:post_type=post q:post_status=draft,private,publish q:tax_query=categoria:releases fn:ethos\\set_post_type_post
if [ $? -ne 0 ]; then
    echo "Erro ao executar o comando."
    exit 1
fi

wp modify-posts q:post_type=post q:post_status=draft,private,publish q:tax_query=category:sem-categoria fn:ethos\\remove_sem_categoria
if [ $? -ne 0 ]; then
    echo "Erro ao executar o comando."
    exit 1
fi

echo "Fim da execução dos comandos."
