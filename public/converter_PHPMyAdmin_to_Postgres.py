import re
import sys

def convert_mysql_to_postgres(sql):
    """
    Converte dumps SQL do MySQL para sintaxe compatível com PostgreSQL.
    """
    
    # 1. Remover comentários e configurações iniciais do MySQL
    sql = re.sub(r'/\*!.*?\*/;', '', sql, flags=re.DOTALL)
    sql = re.sub(r'^SET .*?;', '', sql, flags=re.MULTILINE)
    sql = re.sub(r'^LOCK TABLES .*?;', '', sql, flags=re.MULTILINE)
    sql = re.sub(r'^UNLOCK TABLES;', '', sql, flags=re.MULTILINE)
    sql = re.sub(r'^DROP TABLE IF EXISTS', 'DROP TABLE IF EXISTS', sql, flags=re.MULTILINE)

    # 2. Ajustar Aspas (Identificadores)
    # MySQL usa crase (`), Postgres usa aspas duplas (")
    sql = sql.replace('`', '"')
    
    # 3. Tratamento de Strings e Escapes
    # MySQL usa \' para aspas simples, Postgres usa '' (duas aspas simples)
    # Nota: Isso é arriscado se houver binários, mas funciona para texto padrão
    sql = sql.replace("\\'", "''")
    sql = sql.replace('\\"', '"')

    # 4. Tipos de Dados e Constraints
    
    # AUTO_INCREMENT -> SERIAL
    # Captura int(11) NOT NULL AUTO_INCREMENT e variações
    sql = re.sub(r'(?:int|integer|bigint|smallint)(?:\(\d+\))?\s+(?:unsigned\s+)?NOT\s+NULL\s+AUTO_INCREMENT', 'SERIAL', sql, flags=re.IGNORECASE)
    sql = re.sub(r'(?:int|integer|bigint|smallint)(?:\(\d+\))?\s+(?:unsigned\s+)?AUTO_INCREMENT', 'SERIAL', sql, flags=re.IGNORECASE)

    # Inteiros (Remover tamanho de exibição ex: int(11) -> integer)
    sql = re.sub(r'\b(?:tinyint|smallint|mediumint|int|integer|bigint)\(\d+\)', lambda m: m.group(0).split('(')[0], sql, flags=re.IGNORECASE)
    
    # Tratamento especial para TINYINT(1) que geralmente é BOOLEAN
    # Se preferir manter como número, comente a linha abaixo
    sql = re.sub(r'\btinyint\s', 'smallint ', sql, flags=re.IGNORECASE)

    # Tipos Flutuantes
    sql = re.sub(r'\bfloat\b', 'real', sql, flags=re.IGNORECASE)
    sql = re.sub(r'\bdouble\b', 'double precision', sql, flags=re.IGNORECASE)

    # Blobs e Binários
    sql = re.sub(r'\b(?:mediumblob|longblob|blob|binary|varbinary)\b', 'bytea', sql, flags=re.IGNORECASE)

    # Datas e Tempo
    sql = re.sub(r'\bdatetime\b', 'timestamp', sql, flags=re.IGNORECASE)
    
    # Enum -> Text (Postgres tem ENUM, mas a conversão direta é complexa, TEXT com Check é mais seguro para migração rápida)
    sql = re.sub(r'enum\((.*?)\)', 'text CHECK (VALUE IN (\1))', sql, flags=re.IGNORECASE)

    # 5. Limpeza de Definições de Tabela (Fim do CREATE TABLE)
    # Remove ENGINE=InnoDB DEFAULT CHARSET=utf8...
    sql = re.sub(r'\)\s*ENGINE=.*?;', ');', sql, flags=re.DOTALL)
    sql = re.sub(r'\)\s*DEFAULT\s+CHARSET=.*?;', ');', sql, flags=re.DOTALL)

    # 6. Chaves e Índices dentro do CREATE TABLE
    # Postgres não gosta de definições de KEY/INDEX soltas dentro do create table (exceto PRIMARY e UNIQUE)
    # Remove linhas como: KEY "idx_nome" ("coluna"),
    sql = re.sub(r',\s*KEY\s+".*?"\s*\(.*?\)', '', sql, flags=re.IGNORECASE)
    sql = re.sub(r',\s*INDEX\s+".*?"\s*\(.*?\)', '', sql, flags=re.IGNORECASE)
    
    # Arrumar vírgula sobrando antes do fecha parênteses final
    sql = re.sub(r',\s*\n\);', '\n);', sql)

    # 7. Valores Inválidos
    # MySQL aceita '0000-00-00', Postgres não.
    sql = sql.replace("'0000-00-00 00:00:00'", "NULL")
    sql = sql.replace("'0000-00-00'", "NULL")

    return sql

def main():
    if len(sys.argv) < 2:
        print("Uso: python converter.py arquivo_mysql.sql [arquivo_saida.sql]")
        sys.exit(1)

    input_file = sys.argv[1]
    output_file = sys.argv[2] if len(sys.argv) > 2 else input_file.replace('.sql', '_pg.sql')

    print(f"Lendo: {input_file}...")
    
    try:
        # Tenta ler como UTF-8, se falhar tenta Latin-1 (comum em bancos antigos)
        try:
            with open(input_file, 'r', encoding='utf-8') as f:
                content = f.read()
        except UnicodeDecodeError:
            print("Aviso: UTF-8 falhou, tentando latin-1...")
            with open(input_file, 'r', encoding='latin-1') as f:
                content = f.read()

        new_content = convert_mysql_to_postgres(content)

        print(f"Gravando: {output_file}...")
        with open(output_file, 'w', encoding='utf-8') as f:
            f.write("-- Convertido por Script Python para PostgreSQL\n")
            f.write("BEGIN;\n") # Inicia transação
            f.write(new_content)
            f.write("\nCOMMIT;\n") # Finaliza transação

        print("Sucesso! Conversão concluída.")

    except Exception as e:
        print(f"Erro fatal: {e}")

if __name__ == "__main__":
    main()