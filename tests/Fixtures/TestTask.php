<?php

namespace Lester\EloquentSalesForce\Tests\Fixtures;

use Lester\EloquentSalesForce\Model;

class TestTask extends Model
{
    protected $table = 'Task';

    public $columns = [
        'Id',
        'WhoId',
        'Subject',
    ];

    public function lead()
    {
        return $this->belongsTo(TestLead::class, 'WhoId');
    }
}
