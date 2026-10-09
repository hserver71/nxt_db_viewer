# /etc/systemd/system/admin-panel.service
[Unit]
Description=Admin Panel (PHP built-in server)
After=network.target
[Service]
Type=simple
WorkingDirectory=/home/nxt/public
ExecStart=/home/nxt/bin/php/bin/php -S 0.0.0.0:8080 -t /home/nxt/public
Restart=always
RestartSec=3
User=root
[Install]
WantedBy=multi-user.target



#sudo systemctl daemon-reload
#sudo systemctl enable --now admin-panel
