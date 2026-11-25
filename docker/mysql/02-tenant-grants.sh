#!/bin/sh

mysql --protocol=socket -uroot -p"${MYSQL_ROOT_PASSWORD}" <<SQL
GRANT ALL PRIVILEGES ON \`clientq_tenant\\_%\`.* TO '${MYSQL_USER}'@'%';
FLUSH PRIVILEGES;
SQL
