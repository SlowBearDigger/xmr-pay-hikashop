CREATE TABLE IF NOT EXISTS `#__xmrpay_txids` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `txid`       VARCHAR(64)  NOT NULL,
    `order_id`   INT UNSIGNED NOT NULL,
    `settled_at` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_xmrpay_txid` (`txid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
