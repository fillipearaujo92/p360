#!/usr/bin/env python3
"""
Conversor de SQL do MySQL para PostgreSQL
Uso: python3 converter.py input.sql output.sql
"""

import re
import sys

def convert_mysql_to_postgres(mysql_sql):
    """Converte SQL do MySQL para PostgreSQL"""
    
    sql = mysql_sql
    
    # Remover comentários de configuração do MySQL
    sql = re.sub(r'/\*!.*?\*/;', '', sql, flags=re.DOTALL)
    
    # Remover SET statements específicos do MySQL
    sql = re.sub(r'SET\s+.*?;', '', sql, flags=re.IGNORECASE)
    
    # Remover backticks e substituir por aspas duplas
    sql = sql.replace('`', '"')
    
    # Converter AUTO_INCREMENT para SERIAL
    sql = re.sub(r'INT(?:EGER)?\s+(?:UNSIGNED\s+)?AUTO_INCREMENT', 'SERIAL', sql, flags=re.IGNORECASE)
    sql = re.sub(r'BIGINT\s+(?:UNSIGNED\s+)?AUTO_INCREMENT', 'BIGSERIAL', sql, flags=re.IGNORECASE)
    
    # Remover UNSIGNED
    sql = re.sub(r'\s+UNSIGNED', '', sql, flags=re.IGNORECASE)
    
    # Converter tipos de dados
    sql = re.sub(r'\bTINYINT\(1\)\b', 'BOOLEAN', sql, flags=re.IGNORECASE)
    sql = re.sub(r'\bTINYINT\b', 'SMALLINT', sql, flags=re.IGNORECASE)
    sql = re.sub(r'\bDATETIME\b', 'TIMESTAMP', sql, flags=re.IGNORECASE)
    sql = re.sub(r'\bLONGTEXT\b', 'TEXT', sql, flags=re.IGNORECASE)
    sql = re.sub(r'\bMEDIUMTEXT\b', 'TEXT', sql, flags=re.IGNORECASE)
    sql = re.sub(r'\bLONGBLOB\b', 'BYTEA', sql, flags=re.IGNORECASE)
    sql = re.sub(r'\bBLOB\b', 'BYTEA', sql, flags=re.IGNORECASE)
    sql = re.sub(r'\bDOUBLE\b', 'DOUBLE PRECISION', sql, flags=re.IGNORECASE)
    
    # Remover ENGINE, CHARSET, COLLATE
    sql = re.sub(r'\s+ENGINE\s*=\s*\w+', '', sql, flags=re.IGNORECASE)
    sql = re.sub(r'\s+DEFAULT\s+CHARSET\s*=\s*\w+', '', sql, flags=re.IGNORECASE)
    sql = re.sub(r'\s+COLLATE\s*=\s*\w+', '', sql, flags=re.IGNORECASE)
    sql = re.sub(r'\s+CHARACTER\s+SET\s+\w+', '', sql, flags=re.IGNORECASE)
    sql = re.sub(r'\s+AUTO_INCREMENT\s*=\s*\d+', '', sql, flags=re.IGNORECASE)
    
    # Converter ON UPDATE CURRENT_TIMESTAMP
    sql = re.sub(r'ON\s+UPDATE\s+CURRENT_TIMESTAMP(?:\(\))?', '', sql, flags=re.IGNORECASE)
    
    # Converter DEFAULT CURRENT_TIMESTAMP para DEFAULT CURRENT_TIMESTAMP
    sql = re.sub(r'DEFAULT\s+CURRENT_TIMESTAMP\(\)', 'DEFAULT CURRENT_TIMESTAMP', sql, flags=re.IGNORECASE)
    
    # Remover COMMENT
    sql = re.sub(r"COMMENT\s+'[^']*'", '', sql, flags=re.IGNORECASE)
    
    # Converter aspas simples em DEFAULT para PostgreSQL
    # Manter como está, pois PostgreSQL aceita aspas simples para valores
    
    # Limpar linhas vazias múltiplas
    sql = re.sub(r'\n\s*\n\s*\n+', '\n\n', sql)
    
    return sql

def main():
    if len(sys.argv) != 3:
        print("Uso: python3 converter.py input.sql output.sql")
        sys.exit(1)
    
    input_file = sys.argv[1]
    output_file = sys.argv[2]
    
    print(f"Lendo arquivo: {input_file}")
    
    try:
        with open(input_file, 'r', encoding='utf-8') as f:
            mysql_sql = f.read()
    except FileNotFoundError:
        print(f"Erro: Arquivo '{input_file}' não encontrado!")
        sys.exit(1)
    
    print("Convertendo SQL...")
    postgres_sql = convert_mysql_to_postgres(mysql_sql)
    
    print(f"Salvando em: {output_file}")
    with open(output_file, 'w', encoding='utf-8') as f:
        f.write(postgres_sql)
    
    print("✅ Conversão concluída com sucesso!")
    print(f"\nPróximos passos:")
    print(f"1. Abra o PGAdmin")
    print(f"2. Crie um banco chamado 'controle_patrimonial_saas'")
    print(f"3. Execute o arquivo: {output_file}")
    print(f"4. Importe os CSVs usando o PGAdmin")

if __name__ == "__main__":
    main()