<?php

use App\Mcp\Servers\BackofficeServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('backoffice', BackofficeServer::class);
