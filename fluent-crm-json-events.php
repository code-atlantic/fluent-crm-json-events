<?php
/*
Plugin Name: FluentCRM - JSON Events
Plugin URI: https://github.com/danieliser/fluent-crm-json-events
Description:
Version:
Author: Code Atlantic LLC
Author URI: https://code-atlantic.com/
License:
License URI:
*/

namespace Custom;

use FluentCrm\App\Models\EventTracker;
use FluentCrm\App\Services\Helper;
use FluentCrm\Framework\Support\Arr;



class CustomEventTrackingHandler
{

    public function register()
    {
        // Handle AJAX Property Name Lookups
        add_filter(
            'fluentcrm_ajax_options_event_tracking_props',
            [ $this, 'getEventTrackingPropsOptions' ],
            10,
            1
        );

        add_action(
            'fluentcrm_automation_conditions_assess_event_tracking_objects',
            [ $this, 'assessEventObjectTrackingConditions' ],
            10,
            3
        );

        add_action(
            'fluentcrm_contacts_filter_event_tracking_objects',
            [ $this, 'applyEventTrackingFilter' ],
            10,
            2
        );

        add_filter(
            'fluent_crm/subscriber_info_widgets',
            [ $this, 'addSubscriberInfoWidgets' ],
            10,
            2
        );
        add_filter(
            'fluent_crm/subscriber_info_widget_event_tracking',
            [ $this, 'addSubscriberInfoWidgets' ],
            10,
            2
        );

        add_filter(
            'fluentcrm_advanced_filter_options',
            [ $this, 'addEventTrackingFilterOptions' ],
            10,
            1
        );

        add_filter(
            'fluent_crm/event_tracking_condition_groups',
            [ $this, 'addEventTrackingConditionOptions' ],
            10,
            1
        );

        add_filter(
            'fluentcrm_automation_condition_groups', function ( $groups ) {
                if (! Helper::isExperimentalEnabled('event_tracking') ) {
                    return $groups;
                }

                $groups['event_tracking_objects'] = [
                'label'    => __('Event Tracking (Objects)', 'fluent-crm'),
                'value'    => 'event_tracking_objects',
                'children' => $this->getConditionItems(),
                ];

                return $groups;
            }
        );
    }

    public function getEventTrackingPropsOptions( $options = [] )
    {
        $event_key = Arr::get($options, 'event_key');

        $rows = EventTracker::select([ 'value', 'event_key', 'title' ])
            ->groupBy('event_key')
            ->orderBy('event_key', 'ASC')
            ->get();

        $formattedItems = [];

        $unique_props = [];

        // Check each row for a value that is a json object.
        // If it is, then we need to parse it and return the propnames and types of values.
        foreach ( $rows as $row ) {
            $title     = $row->getAttribute('title');
            $value     = $row->getAttribute('value');
            $event_key = $row->getAttribute('event_key');

            $value = json_decode($value, true);

            if (! is_object($value) && ! is_array($value) ) {
                continue;
            }

            foreach ( $value as $propName => $propValue ) {
                $key = $event_key . ':' . $propName;

                if (! isset($unique_props[ $key ]) ) {
                    $type = gettype($propValue);

                    if (is_numeric($propValue) ) {
                        $type = is_int($propValue) ? 'int' : 'float';
                    }
                    if (is_bool($propValue) ) {
                        $type = 'bool';
                    }
                    if (is_null($propValue) ) {
                        $type = 'null';
                    }
                    if (is_string($propValue) ) {
                        $object_test = json_decode($propValue);
                        if (is_object($object_test) ) {
                            $type = 'object';
                        }
                    }

                    $unique_props[ $key ] = sprintf(
                        '%s: %s (%s)',
                        $title,
                        $propName,
                        $type
                    );
                }
            }
        }

        foreach ( $unique_props as $key => $value ) {
            $formattedItems[] = [
            'id'    => $key,
            'title' => $value,
            ];
        }

        return $formattedItems;
    }


    public static function assessEventObjectTrackingConditions($passes = true, $conditions, $subscriber)
    {
        if (!Helper::isExperimentalEnabled('event_tracking')) {
            return false;
        }

        $hasSubscriber = Subscriber::where('id', $subscriber->id)->where(
            function ($q) use ($conditions) {
                do_action_ref_array('fluentcrm_contacts_filter_event_tracking_objects', [&$q, $conditions]);
            }
        )->first();

        return (bool)$hasSubscriber;
    }

    public function applyEventTrackingFilter( $query, $filters )
    {
        global $wpdb;
        
        if (! Helper::isExperimentalEnabled('event_tracking') ) {
            return $query;
        }

        foreach ( $filters as $filter ) {
            if (empty($filter['value']) && $filter['value'] === '' ) {
                continue;
            }

            $relation = 'trackingEvents';

            $filterProp = $filter['property'];

            if ($filterProp == 'event_tracking_prop_value' ) {
                $eventPropKey = Arr::get($filter, 'extra_value');

                $key = explode(':', $eventPropKey);

                $eventKey = $key[0];
                $propName = $key[1];
                $propType = isset($key[2]) ? $key[2] : 'string';

                if (! $eventKey ) {
                    continue;
                }

                switch ( $propType ) {
                case 'int':
                    // $query->whereRaw('value', [200])
                }

                $operator = $filter['operator'];

                if ($operator == '=' ) {
                    $query->whereHas(
                        $relation, function ( $q ) use (
                            $filter,
                            $eventKey,
                            $propName,
                            $wpdb,
                        ) {

                            $q
                                ->where(
                                    'event_key',
                                    $eventKey
                                )
                                ->whereRaw("JSON_VALUE(`value`, '$.{$propName}') = ?", [ (float) $filter['value'] ]);
                        }
                    );
                } elseif ($operator == '!=' ) {
                    $query->whereDoesntHave(
                        $relation, function ( $q ) use (
                            $filter,
                            $eventKey,
                            $propName,
                            $wpdb,
                        ) {
                            $q
                                ->where(
                                    'event_key',
                                    $eventKey
                                )
                                ->whereRaw("JSON_VALUE(`value`, '$.{$propName}') = ?", [ (float) $filter['value'] ]);
                        }
                    );
                } elseif (in_array($operator, [ '<', '>' ], true) ) {
                    $query->whereHas(
                        $relation, function ( $q ) use (
                            $filter,
                            $eventKey,
                            $propName,
                            $operator,
                            $wpdb,
                        ) {
                            $q
                                ->where('event_key', $eventKey)
                                ->whereRaw("JSON_VALUE(`value`, '$.{$propName}') {$operator} ?", [ (float) $filter['value'] ]);
                        }
                    );
                } elseif ($operator == 'contains' ) {
                    $query->whereHas(
                        $relation, function ( $q ) use (
                            $filter,
                            $eventKey,
                            $propName,
                            $wpdb,
                        ) {
                            $escapedValue = $wpdb->esc_like($filter['value']);

                            $q
                                ->where('event_key', $eventKey)
                                ->whereRaw("JSON_VALUE(`value`, '$.{$propName}') LIKE '%{$escapedValue}%'");
                        }
                    );
                } elseif ($operator == 'not_contains' ) {
                    $query->whereDoesntHave(
                        $relation, function ( $q ) use (
                            $filter,
                            $eventKey,
                            $propName,
                            $wpdb,
                        ) {
                            $escapedValue = $wpdb->esc_like($filter['value']);

                            $q
                                ->where('event_key', $eventKey)
                                ->whereRaw("JSON_VALUE(`value`, '$.{$propName}') LIKE '%{$escapedValue}%'");
                        }
                    );
                }
                continue;
            }
        }

        return $query;
    }

    public function addSubscriberInfoWidgets( $widgets, $subscriber )
    {
        if (! Helper::isExperimentalEnabled('event_tracking') ) {
            return $widgets;
        }

        $events = EventTracker::where('subscriber_id', $subscriber->id)
        ->orderBy('updated_at', 'DESC')
        ->paginate();

        if ($events->isEmpty() ) {
            return $widgets;
        }

        $html =
        '<div class="fc_scrolled_lists"><ul class="fc_full_listed fc_event_tracking_lists">';
        foreach ( $events as $event ) {
            $html .= '<li>';
            $html .=
            '<div class="el-badge"><p class="fc_type">' .
            esc_attr($event->event_key) .
            '</p><sup class="el-badge__content is-fixed">' .
            $event->counter .
            '</sup></div>';
            $html .=
            '<p class="fl_event_title"><b>' .
            esc_html($event->title) .
            '</b></p>';
            if ($event->value ) {
                $object = json_decode($event->value);

                if (! is_object($object) ) {
                    $html .=
                     '<p class="fc_value">' .
                     wp_kses_post($event->value) .
                     '</p>';
                } else {
                    // Foreach property of the object
                    foreach ( $object as $key => $value ) {
                        $html .=
                         '<p class="fc_value"><strong>' .
                         esc_html($key) .
                         ':</strong> ' .
                         wp_kses_post($value) .
                         '</p>';
                    }
                }
            }
            $html .= '<span class="fc_date">' . $event->updated_at . '</span>';
            $html .= '</li>';
        }
        $html .= '</ul></div>';

        $widgets['event_tracking_object'] = [
        'title'          => __('Event Tracking (Objects)', 'fluent-crm'),
        'content'        => $html,
        'has_pagination' => $events->total() > $events->perPage(),
        'total'          => $events->total(),
        'per_page'       => $events->perPage(),
        'current_page'   => $events->currentPage(),
        ];

        return $widgets;
    }

    public function addEventTrackingFilterOptions( $groups )
    {
        if (! Helper::isExperimentalEnabled('event_tracking') ) {
            return $groups;
        }

        $groups['event_tracking_objects'] = [
        'label'    => __('Event Tracking (Objects)', 'fluent-crm'),
        'value'    => 'event_tracking_objects',
        'children' => $this->getConditionItems(),
        ];

        return $groups;
    }

    public function addEventTrackingConditionOptions( $items )
    {
        if (! Helper::isExperimentalEnabled('event_tracking') ) {
            return $items;
        }

        return [
        [
        'label'    => __('Event Tracking (Objects)', 'fluent-crm'),
        'value'    => 'event_tracking_objects',
        'children' => $this->getConditionItems(),
        ],
        // [
        // 'label'    => __('Contact Segment', 'fluent-crm'),
        // 'value'    => 'segment',
        // 'children' => [
        // [
        // 'label'             => __('Type', 'fluent-crm'),
        // 'value'             => 'contact_type',
        // 'type'              => 'selections',
        // 'component'         => 'options_selector',
        // 'option_key'        => 'contact_types',
        // 'is_multiple'       => false,
        // 'is_singular_value' => true
        // ],
        // [
        // 'label'       => __('Tags', 'fluent-crm'),
        // 'value'       => 'tags',
        // 'type'        => 'selections',
        // 'component'   => 'options_selector',
        // 'option_key'  => 'tags',
        // 'is_multiple' => true,
        // ],
        // [
        // 'label'       => __('Lists', 'fluent-crm'),
        // 'value'       => 'lists',
        // 'type'        => 'selections',
        // 'component'   => 'options_selector',
        // 'option_key'  => 'lists',
        // 'is_multiple' => true,
        // ]
        // ],
        // ]
        ];
    }

    private function getConditionItems()
    {
        return [
        [
        'label'            => __('Event Prop Value', 'fluent-crm'),
        'value'            => 'event_tracking_prop_value',
        'type'             => 'composite_optioned_compare',
        'help'             => 'The compare value will be matched with selected event & last recorded value of the selected event prop',
        'ajax_selector'    => [
        'label'              => 'For Event Value Prop',
        'option_key'         => 'event_tracking_props',
        'experimental_cache' => true,
        'is_multiple'        => false,
        'placeholder'        => 'Select Event Value Prop',
        ],
        'value_config'     => [
                    'label'       => 'Compare Value',
                    'type'        => 'input_text',
                    'placeholder' => 'Prop Value',
                    'data_type'   => 'string',
        ],
        'custom_operators' => [
                    '='            => 'equal',
                    '!='           => 'not equal',
                    'contains'     => 'includes',
                    'not_contains' => 'does not includes',
                    '>'            => 'greater than',
                    '<'            => 'less than',
        ],
        ],
        ];
    }
}

add_action(
    'init', function () {
        ( new \Custom\CustomEventTrackingHandler() )->register();
    }
);
