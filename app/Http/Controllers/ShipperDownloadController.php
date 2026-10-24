<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ShipperDownloadController extends Controller
{
    /**
     * The shipper, one shell script to install on a site's server.
     */
    public function __invoke(): BinaryFileResponse
    {
        return response()->download(base_path('tools/edgelog-ship'), 'edgelog-ship', [
            'Content-Type' => 'text/x-shellscript; charset=utf-8',
        ]);
    }
}
