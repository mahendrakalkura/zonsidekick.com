How to install?
===============

Step 1
------

```
$ mkdir zonsidekick.com
$ cd zonsidekick
$ git clone --recursive git@bitbucket.org:mahendrakalkura/zonsidekick.com.git .
$ cp variables.json.sample variables.json # edit variables.json as required
```

Step 2
------

```
$ cd zonsidekick.com
$ mysql -e 'CREATE DATABASE `zonsidekick.com`'
$ mysql zonsidekick.com < files/1.sql
$ mysql zonsidekick.com < files/2.sql
$ mysql zonsidekick.com < files/3.sql
$ mysql zonsidekick.com < files/4.sql
$ mysql zonsidekick.com < files/5.sql
$ mysql zonsidekick.com < files/6.sql
$ mysql zonsidekick.com < files/7.sql
$ mysql zonsidekick.com < files/8.sql
$ mysql zonsidekick.com < files/9.sql
$ mysql zonsidekick.com < files/10.sql
```

Step 3
------

```
$ cd zonsidekick.com
$ composer install
$ bower install
```

Step 4
------

```
$ cd zonsidekick.com
$ mkvirtualenv zonsidekick.com
$ pip install -r requirements.txt
```

Step 5
------

```
$ cd zonsidekick.com/vendor/pixeladmin
$ npm install
$ grunt compile-js
$ grunt compile-less
$ grunt compile-sass
```

How to run?
===========

```
$ cd zonsidekick.com
$ php -S 0.0.0.0:5000
```

Others
======

crontab
-------

```
*/30 * * * * cd {{ path }}/scripts && {{ virtualenv }}/python popular_searches.py
0 */6 * * * cd {{ path }} && {{ virtualenv }}/scrapy crawl top_100_explorer
```

supervisor
----------

```
[program:keyword_analyzer]
autorestart=true
autostart=true
command={{ virtualenv }}/python keyword_analyzer.py
directory=cd {{ path }}/scripts
startsecs=0
```
