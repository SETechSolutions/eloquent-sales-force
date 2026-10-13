<?php

namespace SETechSolutions\EloquentSalesForce\Tests\Fixtures;

use SETechSolutions\EloquentSalesForce\Model;

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
