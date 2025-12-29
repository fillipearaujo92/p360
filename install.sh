#!/bin/bash

echo "======================================"
echo "  Patrimônio 360º - Instalação"
echo "======================================"
echo ""

# Cores para output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# 1. Criar diretórios necessários
echo -e "${YELLOW}[1/4] Criando diretórios...${NC}"
mkdir -p uploads/signatures
mkdir -p logs

# 2. Configurar permissões (Linux/macOS)
echo -e "${YELLOW}[2/4] Configurando permissões...${NC}"
chmod -R 777 uploads
chmod -R 755 logs

echo -e "${GREEN}✓ Permissões configuradas${NC}"
echo -e "  Nota: Backups serão criados no diretório temporário do sistema"

# 3. Verificar extensões PHP
echo -e "${YELLOW}[3/4] Verificando extensões PHP...${NC}"

# Verificar PDO PostgreSQL
if php -m | grep -q "pdo_pgsql"; then
    echo -e "${GREEN}✓ pdo_pgsql: instalado${NC}"
else
    echo -e "${RED}✗ pdo_pgsql: NÃO instalado${NC}"
    echo "  Para instalar no Ubuntu/Debian: sudo apt-get install php-pgsql"
    echo "  Para instalar no macOS (XAMPP): já vem instalado, apenas ative no php.ini"
fi

# Verificar ZIP
if php -m | grep -q "zip"; then
    echo -e "${GREEN}✓ zip: instalado${NC}"
else
    echo -e "${RED}✗ zip: NÃO instalado${NC}"
    echo "  Para instalar no Ubuntu/Debian: sudo apt-get install php-zip"
    echo "  Para instalar no macOS (XAMPP): já vem instalado, apenas ative no php.ini"
fi

# 4. Criar arquivo .gitkeep nos diretórios vazios
echo -e "${YELLOW}[4/4] Finalizando...${NC}"
touch uploads/.gitkeep
touch logs/.gitkeep

echo ""
echo -e "${GREEN}======================================"
echo "  Instalação concluída!"
echo "======================================${NC}"
echo ""
echo "Próximos passos:"
echo "1. Configure o arquivo config.php com suas credenciais PostgreSQL"
echo "2. Importe o schema do banco de dados (se necessário)"
echo "3. Acesse o sistema pelo navegador"
echo ""
echo "Para desenvolvimento local (XAMPP):"
echo "  http://localhost/assetmanager/public/"
echo ""
echo "Para produção (Render):"
echo "  Configure as variáveis de ambiente no painel do Render"
echo ""