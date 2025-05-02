# Simple Paste Bin

## If you liked it you can support my work
[!["Buy Me A Coffee"](https://raw.githubusercontent.com/michal-repo/random_stuff/refs/heads/main/bmac_small.png)](https://buymeacoffee.com/michaldev)


### Screenshots

![1](https://github.com/michal-repo/Simple-Paste-Bin/blob/main/Screenshots/1.png?raw=true)
![2](https://github.com/michal-repo/Simple-Paste-Bin/blob/main/Screenshots/2.png?raw=true)
![3](https://github.com/michal-repo/Simple-Paste-Bin/blob/main/Screenshots/3.png?raw=true)
![4](https://github.com/michal-repo/Simple-Paste-Bin/blob/main/Screenshots/4.png?raw=true)


### Setup

    cp .env.example .env
    nano .env
    composer install
    ./vendor/bin/doctrine-migrations migrate


### Apache

    <Directory /var/www/html/Simple-Paste-Bin>
            AllowOverride All
    </Directory>