<?php

namespace Icinga\Module\Director\Tables;

use Icinga\Module\Director\Web\Table\QuickTable;

class IcingaNotificationTable extends QuickTable
{
    protected $searchColumns = array(
        'notification',
    );

    public function getColumns()
    {
        return array(
            'id'                    => 'n.id',
            'object_type'           => 'n.object_type',
            'notification'          => 'n.object_name',
        );
    }

    protected function getActionUrl($row)
    {
        return $this->url('director/notification', array('id' => $row->id));
    }

    public function getTitles()
    {
        $view = $this->view();
        return array(
            'notification' => $view->translate('Notification'),
        );
    }


    public function getUnfilteredQuery()
    {
        return $this->db()->select()->from(
            array('n' => 'icinga_notification'),
            array()
        );
    }

    public function getBaseQuery()
    {
        return $this->getUnfilteredQuery();
    }
}
