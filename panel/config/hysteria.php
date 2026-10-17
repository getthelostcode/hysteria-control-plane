<?php

return [
    // 节点多久没心跳算离线（秒）；register-hysteria 的节点默认不会心跳，部署后手动标记为在线
    'node_online_ttl' => (int) env('NODE_ONLINE_TTL', 90),

    // 心跳写库节流（秒）：同一节点在该窗口内只落一次 last_seen_at，防止高频心跳打库
    'heartbeat_write_gate' => (int) env('HEARTBEAT_WRITE_GATE', 15),

    // 注册接口是否要求共享密钥（留空 = 不校验；生产建议填）
    'register_shared_secret' => env('REGISTER_SHARED_SECRET', ''),
];
