<?php
require_once __DIR__ . '/../includes/member_auth.php';
memberAuth()->logout();
redirect('/member/login.php');
