#!/bin/bash
cd "$(dirname "$0")"

PATH=/home/devkit8/.rvm/rubies/ruby-2.1.2/bin:$PATH
PATH=/home/devkit8/.rvm/gems/ruby-2.1.2@global/bin:$PATH

bundle install
bundle exec compass watch