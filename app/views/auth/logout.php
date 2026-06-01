<?php

declare(strict_types=1);

logout_current_user();
redirect_with('login', 'info', 'Sessione terminata.');

