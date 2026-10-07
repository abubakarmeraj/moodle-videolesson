# SPDX-License-Identifier: GPL-3.0-or-later
# Copyright 2026 CodeFortex
"""Independent MariaDB DML; all user values are bound parameters."""
import os
from contextlib import contextmanager
import pymysql
from . import config
from pymysql.constants import CLIENT

@contextmanager
def transaction():
    connection = pymysql.connect(host=os.environ['DB_HOST'], user=os.environ['DB_USER'],
        password=config.required('DB_PASSWORD'), database=os.environ['DB_NAME'], charset='utf8mb4',
        cursorclass=pymysql.cursors.DictCursor, autocommit=False, connect_timeout=5,
        read_timeout=30, write_timeout=30, client_flag=CLIENT.FOUND_ROWS)
    try:
        with connection.cursor() as cursor:
            yield cursor
        connection.commit()
    except BaseException:
        connection.rollback()
        raise
    finally:
        connection.close()

def one(cursor, sql, args=()):
    cursor.execute(sql, args)
    return cursor.fetchone()
