<?php
/*
** Zabbix
** Copyright (C) 2001-2014 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


/*
 * Function: get_service_status
 *
 * Description:
 *     Retrieve true status
 *
 * Author:
 *     Aly
 *
 * Comments:
 *		Don't forget to sync code with C!!!!
 */
function get_service_status($serviceid, $algorithm, $triggerid = 0, array $triggerInfo = null, array $calculatedStatuses = array()) {
	if ($algorithm != SERVICE_ALGORITHM_MAX && $algorithm != SERVICE_ALGORITHM_MIN) {
		return 0;
	}

	if (0 != $triggerid) {
		if ($triggerInfo['status'] == TRIGGER_STATUS_ENABLED && $triggerInfo['value'] == TRIGGER_VALUE_TRUE) {
			return $triggerInfo['priority'];
		}
		else {
			return 0;
		}
	}

	$result = DBselect(
		'SELECT s.serviceid,s.status'.
		' FROM services s,services_links sl'.
		' WHERE s.serviceid=sl.servicedownid'.
			' AND sl.serviceupid='.zbx_dbstr($serviceid)
	);

	$status = 0;
	$statuses = array();

	while ($row = DBfetch($result)) {
		$statuses[] = isset($calculatedStatuses[$row['serviceid']])
			? $calculatedStatuses[$row['serviceid']]
			: $row['status'];
	}

	if ($statuses) {
		if ($algorithm == SERVICE_ALGORITHM_MAX) {
			rsort($statuses);
		}
		else {
			sort($statuses);
		}

		$status = $statuses[0];
	}

	return $status;
}

function serviceAlgorythm($algorythm = null) {
	$algorythms = array(
		SERVICE_ALGORITHM_MAX => _('Problem, if at least one child has a problem'),
		SERVICE_ALGORITHM_MIN => _('Problem, if all children have problems'),
		SERVICE_ALGORITHM_NONE => _('Do not calculate')
	);

	if ($algorythm === null) {
		return $algorythms;
	}
	elseif (isset($algorythms[$algorythm])) {
		return $algorythms[$algorythm];
	}
	else {
		return false;
	}
}

function get_service_childs($serviceid, $soft = 0) {
	$childs = array();

	$result = DBselect(
		'SELECT sl.servicedownid'.
		' FROM services_links sl'.
		' WHERE sl.serviceupid='.zbx_dbstr($serviceid).
			($soft ? '' : ' AND sl.soft=0')
	);
	while ($row = DBfetch($result)) {
		$childs[] = $row['servicedownid'];
		$childs = array_merge($childs, get_service_childs($row['servicedownid']));
	}
	return $childs;
}

/**
 * Creates nodes that can be used to display the service configuration tree using the CTree class.
 *
 * @see CTree
 *
 * @param array $services
 * @param array $parentService
 * @param array $service
 * @param array $dependency
 * @param array $tree
 */
function createServiceConfigurationTree(array $services, &$tree, array $parentService = array(), array $service = array(), array $dependency = array()) {
	if (!$service) {
		$caption = new CLink(_('root'), '#');
		$caption->setMenuPopup(CMenuPopupHelper::getServiceConfiguration(null, _('root'), false));

		$serviceNode = array(
			'id' => 0,
			'parentid' => 0,
			'caption' => $caption,
			'trigger' => array(),
			'algorithm' => SPACE,
			'description' => SPACE
		);

		$service = $serviceNode;
		$service['serviceid'] = 0;
		$service['dependencies'] = array();
		$service['trigger'] = array();

		// add all top level services as children of "root"
		foreach ($services as $topService) {
			if (!$topService['parent']) {
				$service['dependencies'][] = array(
					'servicedownid' => $topService['serviceid'],
					'soft' => 0,
					'linkid' => 0
				);
			}
		}

		$tree = array($serviceNode);
	}
	else {
		// caption
		$caption = new CLink($service['name'], '#');

		// service is deletable only if it has no hard dependency
		$deletable = true;
		foreach ($service['dependencies'] as $dep) {
			if ($dep['soft'] == 0) {
				$deletable = false;
				break;
			}
		}

		$caption->setMenuPopup(CMenuPopupHelper::getServiceConfiguration($service['serviceid'], $service['name'], $deletable));

		$serviceNode = array(
			'id' => $service['serviceid'],
			'caption' => $caption,
			'description' => $service['trigger'] ? $service['trigger']['description'] : '-',
			'parentid' => $parentService ? $parentService['serviceid'] : 0,
			'algorithm' => serviceAlgorythm($service['algorithm'])
		);
	}

	if (!$dependency || !$dependency['soft']) {
		$tree[$serviceNode['id']] = $serviceNode;

		foreach ($service['dependencies'] as $dependency) {
			$childService = $services[$dependency['servicedownid']];
			createServiceConfigurationTree($services, $tree, $service, $childService, $dependency);
		}
	}
	else {
		$serviceNode['caption'] = new CSpan($serviceNode['caption'], 'service-caption-soft');

		$tree[$serviceNode['id'].'.'.$dependency['linkid']] = $serviceNode;
	}
}

/**
 * Creates nodes that can be used to display the SLA report tree using the CTree class.
 *
 * @see CTree
 *
 * @param array $services       an array of services to display in the tree
 * @param array $slaData        sla report data, see CService::getSla()
 * @param $period
 * @param array $parentService
 * @param array $service
 * @param array $dependency
 * @param array $tree
 */
function createServiceMonitoringTree(array $services, array $slaData, $period, &$tree, array $parentService = array(), array $service = array(), array $dependency = array()) {
	// if no parent service is given, start from the root
	if (!$service) {
		$serviceNode = array(
			'id' => 0,
			'parentid' => 0,
			'caption' => _('root'),
			'status' => SPACE,
			'sla' => SPACE,
			'sla2' => SPACE,
			'trigger' => array(),
			'reason' => SPACE,
			'graph' => SPACE,
		);

		$service = $serviceNode;
		$service['serviceid'] = 0;
		$service['dependencies'] = array();
		$service['trigger'] = array();

		// add all top level services as children of "root"
		foreach ($services as $topService) {
			if (!$topService['parent']) {
				$service['dependencies'][] = array(
					'servicedownid' => $topService['serviceid'],
					'soft' => 0,
					'linkid' => 0
				);
			}
		}

		$tree = array($serviceNode);
	}
	// create a not from the given service
	else {
		$serviceSla = $slaData[$service['serviceid']];
		$slaValues = reset($serviceSla['sla']);

		// caption
		// remember the selected time period when following the bar link
		$periods = array(
			'today' => 'daily',
			'week' => 'weekly',
			'month' => 'monthly',
			'year' => 'yearly',
			24 => 'daily',
			24 * 7 => 'weekly',
			24 * 30 => 'monthly',
			24 * DAY_IN_YEAR => 'yearly'
		);

		$caption = array(new CLink(
			$service['name'],
			'report3.php?serviceid='.$service['serviceid'].'&year='.date('Y').'&period='.$periods[$period]
		));
		$trigger = $service['trigger'];
		if ($trigger) {
			$url = new CLink($trigger['description'],
				'events.php?filter_set=1&source='.EVENT_SOURCE_TRIGGERS.'&triggerid='.$trigger['triggerid']
			);
			$caption[] = ' - ';
			$caption[] = $url;
		}

		// reason
		$problemList = '-';
		if ($serviceSla['problems']) {
			$problemList = new CList(null, 'service-problems');
			foreach ($serviceSla['problems'] as $problemTrigger) {
				$problemList->addItem(new CLink($problemTrigger['description'],
					'events.php?filter_set=1&source='.EVENT_SOURCE_TRIGGERS.'&triggerid='.$problemTrigger['triggerid']
				));
			}
		}

		// sla
		$sla = '-';
		$sla2 = '-';
		if ($service['showsla'] && $slaValues['sla'] !== null) {
			$slaGood = $slaValues['sla'];
			$slaBad = 100 - $slaValues['sla'];

			$p = min($slaBad, 20);

			$width = 160;
			$widthRed = $width * $p / 20;
			$widthGreen = $width - $widthRed;

			$chart1 = null;
			if ($widthGreen > 0) {
				$chart1 = new CDiv(null, 'sla-bar-part sla-green');
				$chart1->setAttribute('style', 'width: '.$widthGreen.'px;');
			}
			$chart2 = null;
			if ($widthRed > 0) {
				$chart2 = new CDiv(null, 'sla-bar-part sla-red');
				$chart2->setAttribute('style', 'width: '.$widthRed.'px;');
			}
			$bar = new CLink(array(
				$chart1,
				$chart2,
				new CDiv('80%', 'sla-bar-legend sla-bar-legend-start'),
				new CDiv('100%', 'sla-bar-legend sla-bar-legend-end')
			), 'srv_status.php?serviceid='.$service['serviceid'].'&showgraph=1'.url_param('path'));
			$bar = new CDiv($bar, 'sla-bar');
			$bar->setAttribute('title', _s('Only the last 20%% of the indicator is displayed.'));

			$slaBar = array(
				$bar,
				new CSpan(sprintf('%.4f', $slaBad), 'sla-value '.(($service['goodsla'] > $slaGood) ? 'red' : 'green'))
			);

			$sla = new CDiv($slaBar, 'invisible');
			$sla2 = array(
				new CSpan(sprintf('%.4f', $slaGood), 'sla-value '.(($service['goodsla'] > $slaGood) ? 'red' : 'green')),
				'/',
				new CSpan(sprintf('%.4f', $service['goodsla']), 'sla-value')
			);
		}

		$serviceNode = array(
			'id' => $service['serviceid'],
			'caption' => $caption,
			'description' => ($service['trigger']) ? $service['trigger']['description'] : _('None'),
			'reason' => $problemList,
			'sla' => $sla,
			'sla2' => $sla2,
			'parentid' => ($parentService) ? $parentService['serviceid'] : 0,
			'status' => ($serviceSla['status'] !== null) ? $serviceSla['status'] : '-'
		);
	}

	// hard dependencies and dependencies for the "root" node
	if (!$dependency || $dependency['soft'] == 0) {
		$tree[$serviceNode['id']] = $serviceNode;

		foreach ($service['dependencies'] as $dependency) {
			$childService = $services[$dependency['servicedownid']];
			createServiceMonitoringTree($services, $slaData, $period, $tree, $service, $childService, $dependency);
		}
	}
	// soft dependencies
	else {
		$serviceNode['caption'] = new CSpan($serviceNode['caption'], 'service-caption-soft');

		$tree[$serviceNode['id'].'.'.$dependency['linkid']] = $serviceNode;
	}
}

/*
 * Calculates the current IT service status
 *
 */
function calculateITServiceStatus($serviceId, $servicesLinks, &$services, $triggers) {
	$service = &$services[$serviceId];

	if (isset($service['newStatus'])) {
		// don't calculate a thread if it is already calculated
		// it can be with soft links
		return;
	}

	$newStatus = 0;

	if ($service['triggerid'] != 0) {
		if ($service['algorithm'] == SERVICE_ALGORITHM_MAX || $service['algorithm'] == SERVICE_ALGORITHM_MIN) {
			$trigger = $triggers[$service['triggerid']];

			if ($trigger['status'] == TRIGGER_STATUS_ENABLED && $trigger['value'] == TRIGGER_VALUE_TRUE) {
				$newStatus = $trigger['priority'];
			}
		}
	}
	else {
		$statuses = array();

		if (isset($servicesLinks[$service['serviceid']])) {
			foreach ($servicesLinks[$service['serviceid']] as $serviceId) {
				calculateITServiceStatus($serviceId, $servicesLinks, $services, $triggers);
				$statuses[] = $services[$serviceId]['newStatus'];
			}
		}

		if ($service['algorithm'] == SERVICE_ALGORITHM_MAX || $service['algorithm'] == SERVICE_ALGORITHM_MIN) {
			if ($statuses) {
				if ($service['algorithm'] == SERVICE_ALGORITHM_MAX) {
					rsort($statuses);
				}
				else {
					sort($statuses);
				}

				$newStatus = $statuses[0];
			}
		}
	}

	$service['newStatus'] = $newStatus;
}

/*
 * Updates the status of all IT services
 *
 */
function updateITServices() {
	$servicesLinks = array();
	$services = array();
	$rootServiceIds = array();
	$triggers = array();

	// auxiliary arrays
	$triggerIds = array();
	$servicesLinksDown = array();

	$result = DBselect('SELECT sl.serviceupid,sl.servicedownid FROM services_links sl');

	while ($row = DBfetch($result)) {
		$servicesLinks[$row['serviceupid']][] = $row['servicedownid'];
		$servicesLinksDown[$row['servicedownid']] = true;
	}

	$result = DBselect('SELECT s.serviceid,s.algorithm,s.triggerid,s.status FROM services s ORDER BY s.serviceid');

	while ($row = DBfetch($result)) {
		$services[$row['serviceid']] = array(
			'serviceid' => $row['serviceid'],
			'algorithm' => $row['algorithm'],
			'triggerid' => $row['triggerid'],
			'status' => $row['status']
		);

		if (!isset($servicesLinksDown[$row['serviceid']])) {
			$rootServiceIds[] = $row['serviceid'];
		}

		if ($row['triggerid'] != 0) {
			$triggerIds[$row['triggerid']] = true;
		}
	}

	if ($triggerIds) {
		$result = DBselect(
			'SELECT t.triggerid,t.priority,t.status,t.value'.
			' FROM triggers t'.
			' WHERE '.dbConditionInt('t.triggerid', array_keys($triggerIds))
		);

		while ($row = DBfetch($result)) {
			$triggers[$row['triggerid']] = array(
				'priority' => $row['priority'],
				'status' => $row['status'],
				'value' => $row['value']
			);
		}
	}

	// clearing auxiliary variables
	unset($triggerIds, $servicesLinksDown);

	// calculating data
	foreach ($rootServiceIds as $rootServiceId) {
		calculateITServiceStatus($rootServiceId, $servicesLinks, $services, $triggers);
	}

	// updating changed data
	$updates = array();
	$inserts = array();
	$clock = time();

	foreach ($services as $service) {
		if ($service['newStatus'] != $service['status']) {
			$updates[] = array(
				'values' => array('status' => $service['newStatus']),
				'where' =>  array('serviceid' => $service['serviceid'])
			);
			$inserts[] = array(
				'serviceid' => $service['serviceid'],
				'clock' => $clock,
				'value' => $service['newStatus']
			);
		}
	}

	if ($updates) {
		DB::update('services', $updates);
		DB::insert('service_alarms', $inserts);
	}
}

/**
 * Validate the new service time. Validation is implemented as a separate function to be available directly from the
 * frontend.
 *
 * @throws APIException if the given service time is invalid
 *
 * @param array $serviceTime
 *
 * @return void
 */
function checkServiceTime(array $serviceTime) {
	// type validation
	$serviceTypes = array(
		SERVICE_TIME_TYPE_DOWNTIME,
		SERVICE_TIME_TYPE_ONETIME_DOWNTIME,
		SERVICE_TIME_TYPE_UPTIME
	);
	if (!isset($serviceTime['type']) || !in_array($serviceTime['type'], $serviceTypes)) {
		throw new APIException(ZBX_API_ERROR_PARAMETERS, _('Incorrect service time type.'));
	}

	// one-time downtime validation
	if ($serviceTime['type'] == SERVICE_TIME_TYPE_ONETIME_DOWNTIME) {
		if (!isset($serviceTime['ts_from']) || !validateUnixTime($serviceTime['ts_from'])) {
			throw new APIException(ZBX_API_ERROR_PARAMETERS, _('Incorrect service start time.'));
		}
		if (!isset($serviceTime['ts_to']) || !validateUnixTime($serviceTime['ts_to'])) {
			throw new APIException(ZBX_API_ERROR_PARAMETERS, _('Incorrect service end time.'));
		}
	}
	// recurring downtime validation
	else {
		if (!isset($serviceTime['ts_from']) || !zbx_is_int($serviceTime['ts_from']) || $serviceTime['ts_from'] < 0 || $serviceTime['ts_from'] > SEC_PER_WEEK) {
			throw new APIException(ZBX_API_ERROR_PARAMETERS, _('Incorrect service start time.'));
		}
		if (!isset($serviceTime['ts_to']) || !zbx_is_int($serviceTime['ts_to']) || $serviceTime['ts_to'] < 0 || $serviceTime['ts_to'] > SEC_PER_WEEK) {
			throw new APIException(ZBX_API_ERROR_PARAMETERS, _('Incorrect service end time.'));
		}
	}

	if ($serviceTime['ts_from'] >= $serviceTime['ts_to']) {
		throw new APIException(ZBX_API_ERROR_PARAMETERS, _('Service start time must be less than end time.'));
	}
}
