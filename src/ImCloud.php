<?php
namespace ImCloud;

use ImCloud\Server\Route;

class ImCloud
{
    public function run()
    {
        (new Route)->start();
    }
}
