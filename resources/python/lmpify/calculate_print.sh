#!/bin/bash
cd /var/www/python/lmpify/
source venv/bin/activate
python3 ./calculate_print.py "$@"
