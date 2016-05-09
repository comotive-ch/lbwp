#!/bin/bash
cd "$(dirname "$0")"

PATH=/home/michael/.rvm/rubies/ruby-2.1.2/bin:$PATH
PATH=/home/michael/.rvm/gems/ruby-2.1.2@global/bin:$PATH

bundle install
bundle exec compass watch