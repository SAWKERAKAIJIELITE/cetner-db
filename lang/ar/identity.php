<?php

return [
    'device_key_invalid' => 'المفتاح العام غير صالح: يجب أن يكون مفتاح ECDSA P-256 بصيغة PEM.',
    'device_pending_limit' => 'طلبات تسجيل الأجهزة المعلّقة كثيرة: راجع مدير النظام.',
    'device_not_pending' => 'هذا الجهاز ليس بانتظار الموافقة.',
    'device_fingerprint_mismatch' => 'البصمة المدخلة لا تطابق بصمة الجهاز.',
    'device_owner_inactive' => 'لا يُعتمد جهاز لمستخدم غير فعّال.',
    'device_owner_has_device' => 'لهذا المستخدم جهاز معتمد: ألغِه أولًا.',
    'device_already_revoked' => 'هذا الجهاز ملغى من قبل.',
    'last_user_manager' => 'لا يمكن إجراء هذا التغيير: يجب أن يبقى مستخدم فعّال واحد على الأقل قادرًا على إدارة المستخدمين.',
    'admin_role_fixed' => 'دور مدير النظام يملك كل الصلاحيات دائمًا ولا يُعدَّل.',
    'read_only_role' => 'دور المدير العام يأخذ صلاحيات العرض فقط: :permissions',
    'admin_role_by_admin_only' => 'لا يمنح دور مدير النظام إلا مدير النظام.',
    'cannot_deactivate_self' => 'لا يمكنك إيقاف حسابك.',
    'already_inactive' => 'هذا الحساب موقوف من قبل.',
    'already_active' => 'هذا الحساب فعّال من قبل.',
    'created_inactive' => 'أُنشئ الحساب غير فعّال.',
    'logged_out' => 'سُجّل الخروج.',
];
