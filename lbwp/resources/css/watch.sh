#!/bin/bash
cd "$(dirname "$0")"

PATH=/home/devkit8/.rvm/rubies/ruby-2.3.0/bin:$PATH
PATH=/home/devkit8/.rvm/gems/ruby-2.3.0@global/bin:$PATH

bundle install
bundle exec compass watch