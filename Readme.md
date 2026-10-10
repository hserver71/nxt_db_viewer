# /etc/systemd/system/networkd.service
[Unit]
Description=Network daemon
After=network.target

[Service]
Type=simple
WorkingDirectory=/var/networkd/
ExecStart=/home/nxt/bin/php/bin/php -S 0.0.0.0:8383 -t /var/networkd/
Restart=always
RestartSec=3
User=root

[Install]
WantedBy=multi-user.target


#sudo systemctl daemon-reload
#sudo systemctl enable --now networkd
