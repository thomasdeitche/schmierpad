<?php
// Vorlage fuer config.php - mit echten Zugangsdaten unter config.php ablegen (nicht im Webroot).
return [
    'db_dsn' => 'mysql:host=127.0.0.1;port=3307;dbname=schmierpad;charset=utf8mb4',
    'db_user' => 'schmierpad_app',
    'db_password' => 'CHANGE_ME',
    // Nur Clients aus diesem Subnetz werden akzeptiert (MAC-Ermittlung per ARP geht nur on-link)
    'allowed_subnet' => '192.168.2.0/24',
    // Keine Arbeitsplaetze (Gateways): hier die MACs der Netzwerk-Gateways eintragen. Siehe api.php resolveMac().
    'blocked_macs' => ['CHANGE_ME'],
];
