/**
 * Trunk Caps panel modules: Trunk Caps (policies), Trunk Cap Usage (live), Trunk Cap Log.
 *
 * Loaded by index.html as a plain script, NOT compiled into the Sencha
 * bundle: MagnusBilling ships one prebuilt app.js per theme, so these classes
 * are defined at runtime on top of the Ext.ux base classes already in it.
 * No Sencha Cmd build is needed and every theme picks them up.
 *
 * This software is released under the terms of the GNU Lesser General Public License v3
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 */
(function () {

    var nullableInt = function (name) {
        return { name: name, type: 'int', allowNull: true };
    };

    function ready() {
        return window.Ext && Ext.ClassManager && window.t && window.icons &&
            Ext.ClassManager.get('Ext.ux.grid.Panel') && Ext.ClassManager.get('Ext.ux.form.Panel') &&
            Ext.ClassManager.get('Ext.ux.panel.Module') && Ext.ClassManager.get('Ext.ux.app.ViewController') &&
            Ext.ClassManager.get('MBilling.view.trunk.Combo');
    }

    function boot() {
        if (!ready()) {
            setTimeout(boot, 100);
            return;
        }
        if (!Ext.ClassManager.get('MBilling.model.TrunkCapPolicy')) {
            define();
        }
    }

    function stateRenderer(value) {
        var colors = { ok: '#2e7d32', warning: '#ef6c00', blocking: '#c62828', paused: '#6a1b9a', outside_window: '#757575' },
            labels = { ok: t('OK'), warning: t('Warning'), blocking: t('At limit'), paused: t('Paused'), outside_window: t('Outside window') };
        return '<span style="color:' + (colors[value] || '#000') + ';font-weight:bold">' + (labels[value] || value) + '</span>';
    }

    function usedOf(used, limit, unit) {
        if (limit === null || limit === undefined) {
            return used === null || used === undefined ? '' : Ext.util.Format.number(used, '0,000.#') + (unit || '');
        }
        return Ext.util.Format.number(used, '0,000.#') + ' / ' + Ext.util.Format.number(limit, '0,000') + (unit || '');
    }

    function define() {
        // ---- models and stores -------------------------------------------------

        Ext.define('MBilling.model.TrunkCapPolicy', {
            extend: 'Ext.data.Model',
            fields: [
                { name: 'id', type: 'int' },
                { name: 'id_trunk', type: 'int' },
                { name: 'idTrunktrunkcode', type: 'string', persist: false },
                { name: 'name', type: 'string' },
                { name: 'active', type: 'int', defaultValue: 1 },
                { name: 'period_type', type: 'string', defaultValue: 'month' },
                nullableInt('period_length_sec'),
                { name: 'anchor', type: 'string', defaultValue: 'calendar' },
                nullableInt('reset_day'),
                { name: 'timezone', type: 'string', defaultValue: 'Africa/Lagos' },
                {
                    name: 'window_start', allowNull: true,
                    convert: function (v) { return v ? String(v).substr(0, 5) : null; }
                },
                {
                    name: 'window_end', allowNull: true,
                    convert: function (v) { return v ? String(v).substr(0, 5) : null; }
                },
                {
                    name: 'weekdays', allowNull: true,
                    convert: function (v) { return Ext.isString(v) && v.length ? v.split(',') : (v || null); }
                },
                nullableInt('max_calls'),
                nullableInt('max_calls_per_hour'),
                nullableInt('max_minutes'),
                nullableInt('max_concurrent'),
                nullableInt('max_cps'),
                { name: 'count_failed_attempts', type: 'int', defaultValue: 0 },
                { name: 'safety_margin_pct', type: 'int', defaultValue: 100 },
                { name: 'warn_pct', type: 'string', defaultValue: '80,90' },
                { name: 'on_limit', type: 'string', defaultValue: 'skip_trunk' },
                { name: 'reserve_mode', type: 'string', defaultValue: 'adaptive' },
                nullableInt('rounding_init_sec'),
                nullableInt('rounding_inc_sec'),
                { name: 'strict', type: 'int', defaultValue: 0 },
                { name: 'override_until', type: 'string', allowNull: true, persist: false },
                { name: 'summary', type: 'string', persist: false }
            ],
            proxy: { type: 'uxproxy', module: 'trunkCapPolicy' }
        });

        Ext.define('MBilling.model.TrunkCapUsage', {
            extend: 'Ext.data.Model',
            idProperty: 'id',
            fields: [
                { name: 'id', type: 'int' }, 'id_trunk', 'trunkcode', 'name', 'summary', 'state',
                'window_start', 'window_end', 'rolling', 'calls', 'max_calls', 'billed_minutes', 'reserved_minutes',
                'max_minutes', 'in_flight', 'max_concurrent', 'pct', 'safety_margin_pct', 'override_until'
            ],
            proxy: { type: 'uxproxy', module: 'trunkCapUsage' }
        });

        Ext.define('MBilling.model.TrunkCapEvent', {
            extend: 'Ext.data.Model',
            fields: [
                { name: 'id', type: 'int' }, 'created_at', 'id_trunk', 'idTrunktrunkcode', 'id_policy', 'idPolicyname',
                'event', 'snapshot', 'uniqueid', 'id_user', 'notified_at'
            ],
            proxy: { type: 'uxproxy', module: 'trunkCapEvent' }
        });

        Ext.define('MBilling.model.TrunkCapStatement', {
            extend: 'Ext.data.Model',
            fields: [
                { name: 'id', type: 'int' },
                { name: 'id_trunk', type: 'int' },
                { name: 'idTrunktrunkcode', type: 'string', persist: false },
                { name: 'period_start', type: 'date', dateFormat: 'Y-m-d', dateWriteFormat: 'Y-m-d' },
                { name: 'period_end', type: 'date', dateFormat: 'Y-m-d', dateWriteFormat: 'Y-m-d' },
                { name: 'timezone', type: 'string', defaultValue: 'Africa/Lagos' },
                { name: 'provider_minutes', type: 'number' },
                nullableInt('provider_calls'),
                { name: 'note', type: 'string' },
                { name: 'our_minutes', persist: false }, { name: 'our_real_minutes', persist: false },
                { name: 'our_calls', persist: false }, { name: 'failed_attempts', persist: false },
                { name: 'counter_minutes', persist: false }, { name: 'diff_pct', persist: false },
                { name: 'status', persist: false }
            ],
            proxy: { type: 'uxproxy', module: 'trunkCapStatement' }
        });

        Ext.each(['TrunkCapPolicy', 'TrunkCapUsage', 'TrunkCapEvent', 'TrunkCapStatement'], function (name) {
            Ext.define('MBilling.store.' + name, { extend: 'Ext.data.Store', model: 'MBilling.model.' + name });
            if (!Ext.data.StoreManager.lookup(name)) {
                Ext.create('MBilling.store.' + name, { storeId: name, remoteSort: true, remoteFilter: true });
            }
        });

        // ---- Trunk Caps (policies) ---------------------------------------------

        // Ext 6 tagfield calls refreshEmptyText() when a local store loads, before render
        // has created emptyClsElements; upstream tag fields only avoid it by loading remotely.
        Ext.define('MBilling.view.trunkCapPolicy.WeekdaysTag', {
            extend: 'Ext.form.field.Tag',
            alias: 'widget.trunkcapweekdays',
            refreshEmptyText: function () {
                if (this.emptyClsElements) {
                    this.callParent(arguments);
                }
            }
        });


        Ext.define('MBilling.view.trunkCapPolicy.Module', {
            extend: 'Ext.ux.panel.Module',
            alias: 'widget.trunkcappolicymodule',
            controller: 'trunkcappolicy',
            cfgEast: { flex: 1.4 }
        });

        Ext.define('MBilling.view.trunkCapPolicy.Controller', {
            extend: 'Ext.ux.app.ViewController',
            alias: 'controller.trunkcappolicy',

            init: function () {
                var me = this;
                me.previewTask = new Ext.util.DelayedTask(me.updatePreview, me);
                me.control({
                    'trunkcappolicyform field': { change: me.onFieldChange }
                });
                me.callParent(arguments);
            },

            onFieldChange: function () {
                this.showFieldsRelated();
                this.previewTask.delay(400);
            },

            showFieldsRelated: function () {
                var form = this.formPanel && this.formPanel.getForm();
                if (!form) {
                    return;
                }
                form.findField('period_length_sec').setVisible(form.findField('period_type').getValue() === 'custom');
                form.findField('reset_day').setVisible(form.findField('anchor').getValue() === 'billing_day');
            },

            updatePreview: function () {
                var me = this,
                    preview = me.formPanel && me.formPanel.down('#trunkCapPreview');
                if (!preview || !me.formPanel.isVisible()) {
                    return;
                }
                Ext.Ajax.request({
                    url: 'index.php/trunkCapPolicy/preview',
                    params: { rows: Ext.encode(me.formPanel.getForm().getFieldValues()) },
                    success: function (response) {
                        var r = Ext.decode(response.responseText, true);
                        preview.setValue(r && r.success ? Ext.String.htmlEncode(r.summary) : '');
                    }
                });
            },

            onNew: function () {
                this.callParent(arguments);
                this.showFieldsRelated();
                this.updatePreview();
            },

            onEdit: function () {
                this.callParent(arguments);
                this.showFieldsRelated();
                this.updatePreview();
            },

            selectedPolicy: function () {
                var record = this.list.getSelectionModel().getSelection()[0];
                if (!record) {
                    Ext.ux.Alert.alert(this.titleWarning, t('Select a policy first.'), 'warning');
                }
                return record;
            },

            post: function (action, params, done) {
                var me = this;
                Ext.Ajax.request({
                    url: 'index.php/trunkCapPolicy/' + action,
                    params: params,
                    success: function (response) {
                        var r = Ext.decode(response.responseText, true) || {};
                        Ext.ux.Alert.alert(r.success ? me.titleSuccess : me.titleError,
                            r.success ? r.msg : (r.errors || t('Error')), r.success ? 'success' : 'error');
                        me.store.load();
                    }
                });
            },

            onResetWindow: function () {
                var me = this,
                    record = me.selectedPolicy();
                if (!record) {
                    return;
                }
                Ext.Msg.confirm(me.titleConfirmation,
                    t('Reset the current counters of') + ' "' + Ext.String.htmlEncode(record.get('name')) + '"? '
                    + t('Calls in progress keep their reservation. This is recorded in the Trunk Cap Log.'),
                    function (answer) {
                        if (answer === 'yes') {
                            me.post('reset', { id: record.get('id') });
                        }
                    });
            },

            onPause: function (item) {
                var record = this.selectedPolicy();
                if (record) {
                    this.post('override', { id: record.get('id'), minutes: item.minutes });
                }
            }
        });

        Ext.define('MBilling.view.trunkCapPolicy.List', {
            extend: 'Ext.ux.grid.Panel',
            alias: 'widget.trunkcappolicylist',
            store: 'TrunkCapPolicy',
            fieldSearch: 'name',
            initComponent: function () {
                var me = this;
                me.buttonUpdateLot = false;
                me.extraButtons = [{
                    text: t('Reset counters'),
                    iconCls: 'x-fa fa-undo',
                    handler: 'onResetWindow',
                    width: 130,
                    hidden: !me.allowUpdate
                }, {
                    text: t('Pause'),
                    iconCls: 'x-fa fa-pause',
                    width: 90,
                    hidden: !me.allowUpdate,
                    menu: {
                        defaults: { handler: 'onPause' },
                        items: [
                            { text: t('1 hour'), minutes: 60 },
                            { text: t('4 hours'), minutes: 240 },
                            { text: t('24 hours'), minutes: 1440 },
                            '-',
                            { text: t('End pause'), minutes: 0 }
                        ]
                    }
                }];
                me.columns = [{
                    header: t('ID'), dataIndex: 'id', flex: 1, hidden: true
                }, {
                    header: t('Trunk'), dataIndex: 'idTrunktrunkcode', flex: 2,
                    filter: { type: 'string', field: 'idTrunk.trunkcode' }
                }, {
                    header: t('Name'), dataIndex: 'name', flex: 2
                }, {
                    header: t('Policy'), dataIndex: 'summary', flex: 7, sortable: false,
                    renderer: function (v) {
                        return '<div style="white-space:normal">' + Ext.String.htmlEncode(v) + '</div>';
                    }
                }, {
                    header: t('Status'), dataIndex: 'active', flex: 1,
                    renderer: function (v, meta, record) {
                        if (!v) {
                            return t('Inactive');
                        }
                        return record.get('override_until') ? '<span style="color:#6a1b9a">' + t('Paused') + '</span>' : t('Active');
                    },
                    filter: { type: 'list', options: [[1, t('Active')], [0, t('Inactive')]] }
                }];
                me.callParent(arguments);
            }
        });

        Ext.define('MBilling.view.trunkCapPolicy.Form', {
            extend: 'Ext.ux.form.Panel',
            alias: 'widget.trunkcappolicyform',
            fieldsHideUpdateLot: [],
            initComponent: function () {
                var me = this,
                    optional = { allowBlank: true, xtype: 'numberfield', minValue: 1, emptyText: t('Unlimited') };

                me.defaults = Ext.apply({}, { labelWidth: 170, anchor: '100%' }, me.defaults);
                me.items = [{
                    xtype: 'displayfield',
                    itemId: 'trunkCapPreview',
                    isFormField: false, // display only: keep it out of getFieldValues() and saves
                    fieldLabel: t('Summary'),
                    labelAlign: 'top',
                    fieldStyle: 'background:#f5f7fa;padding:6px;border-radius:3px;white-space:normal',
                    value: ''
                }, {
                    xtype: 'trunkcombo',
                    name: 'id_trunk',
                    fieldLabel: t('Trunk')
                }, {
                    name: 'name',
                    fieldLabel: t('Name'),
                    maxLength: 100
                }, {
                    xtype: 'booleancombo',
                    name: 'active',
                    fieldLabel: t('Status')
                }, {
                    xtype: 'fieldset',
                    title: t('Period'),
                    defaults: { labelWidth: 160, anchor: '100%', allowBlank: false },
                    items: [{
                        xtype: 'combobox', name: 'period_type', fieldLabel: t('Period'),
                        forceSelection: true, editable: false, value: 'month',
                        store: [['hour', t('Hour')], ['day', t('Day')], ['week', t('Week')], ['month', t('Month')], ['custom', t('Custom')]]
                    }, Ext.apply({}, {
                        name: 'period_length_sec', fieldLabel: t('Custom length (seconds)'), minValue: 60,
                        emptyText: '', hidden: true
                    }, optional), {
                        xtype: 'combobox', name: 'anchor', fieldLabel: t('Resets'),
                        forceSelection: true, editable: false, value: 'calendar',
                        store: [['calendar', t('Calendar (1st, Monday, midnight)')], ['billing_day', t('On a billing day')], ['rolling', t('Rolling window')]]
                    }, Ext.apply({}, {
                        name: 'reset_day', fieldLabel: t('Billing day (1-31, or 1-7 = Mon-Sun)'),
                        minValue: 1, maxValue: 31, emptyText: '', hidden: true
                    }, optional), {
                        xtype: 'textfield', name: 'timezone', fieldLabel: t('Timezone'), value: 'Africa/Lagos'
                    }]
                }, {
                    xtype: 'fieldset',
                    title: t('Time window (optional)'),
                    defaults: { labelWidth: 160, anchor: '100%', allowBlank: true },
                    items: [{
                        xtype: 'textfield', name: 'window_start', fieldLabel: t('From (HH:MM)'), emptyText: t('All day'),
                        regex: /^([01]\d|2[0-3]):[0-5]\d$/, regexText: t('Use HH:MM, e.g. 08:00')
                    }, {
                        xtype: 'textfield', name: 'window_end', fieldLabel: t('To (HH:MM)'), emptyText: t('All day'),
                        regex: /^([01]\d|2[0-3]):[0-5]\d$/, regexText: t('Use HH:MM, e.g. 18:00')
                    }, {
                        xtype: 'trunkcapweekdays', name: 'weekdays', fieldLabel: t('Weekdays (empty = every day)'),
                        queryMode: 'local', filterPickList: true, valueField: 'id', displayField: 'name',
                        store: Ext.create('Ext.data.Store', {
                            fields: ['id', 'name'],
                            data: [
                                { id: 'mon', name: t('Mon') }, { id: 'tue', name: t('Tue') }, { id: 'wed', name: t('Wed') },
                                { id: 'thu', name: t('Thu') }, { id: 'fri', name: t('Fri') }, { id: 'sat', name: t('Sat') },
                                { id: 'sun', name: t('Sun') }
                            ]
                        })
                    }]
                }, {
                    xtype: 'fieldset',
                    title: t('Limits (empty = unlimited)'),
                    defaults: Ext.apply({ labelWidth: 160, anchor: '100%' }, optional),
                    items: [
                        { name: 'max_minutes', fieldLabel: t('Max minutes per period') },
                        { name: 'max_calls', fieldLabel: t('Max calls per period') },
                        { name: 'max_calls_per_hour', fieldLabel: t('Max calls per rolling hour') },
                        { name: 'max_concurrent', fieldLabel: t('Max concurrent calls') },
                        { name: 'max_cps', fieldLabel: t('Max calls per second') },
                        {
                            xtype: 'noyescombo', name: 'count_failed_attempts', fieldLabel: t('Count failed attempts'),
                            allowBlank: false, minValue: undefined, emptyText: ''
                        }
                    ]
                }, {
                    xtype: 'fieldset',
                    title: t('Enforcement'),
                    defaults: { labelWidth: 160, anchor: '100%', allowBlank: false },
                    items: [{
                        xtype: 'numberfield', name: 'safety_margin_pct', fieldLabel: t('Enforce at (% of limit)'),
                        minValue: 1, maxValue: 100, value: 100
                    }, {
                        xtype: 'textfield', name: 'warn_pct', fieldLabel: t('Warn at (%)'), allowBlank: true, value: '80,90',
                        regex: /^(\d{1,2}(,\d{1,2})*)?$/, regexText: t('Comma-separated percentages, e.g. 80,90')
                    }, {
                        xtype: 'combobox', name: 'on_limit', fieldLabel: t('When reached'),
                        forceSelection: true, editable: false, value: 'skip_trunk',
                        store: [['skip_trunk', t('Fail over to next trunk')], ['reject', t('Reject the call')]]
                    }, {
                        xtype: 'combobox', name: 'reserve_mode', fieldLabel: t('Minutes reservation'),
                        forceSelection: true, editable: false, value: 'adaptive',
                        store: [['adaptive', t('Adaptive (default)')], ['full', t('Full duration (never overshoots)')]]
                    }, Ext.apply({}, {
                        name: 'rounding_init_sec', fieldLabel: t('Billing initial block (s)'), minValue: 0,
                        emptyText: t('Provider buy rate')
                    }, optional), Ext.apply({}, {
                        name: 'rounding_inc_sec', fieldLabel: t('Billing increment (s)'), emptyText: t('Provider buy rate')
                    }, optional), {
                        xtype: 'noyescombo', name: 'strict', fieldLabel: t('Strict (block on errors)')
                    }]
                }];
                me.callParent(arguments);
            }
        });

        // ---- Trunk Cap Usage (live, read-only) ---------------------------------

        Ext.define('MBilling.view.trunkCapUsage.Module', {
            extend: 'Ext.ux.panel.Module',
            alias: 'widget.trunkcapusagemodule',
            controller: 'trunkcapusage',
            cfgEast: { flex: 0.8 }
        });

        Ext.define('MBilling.view.trunkCapUsage.Controller', {
            extend: 'Ext.ux.app.ViewController',
            alias: 'controller.trunkcapusage',
            refreshSeconds: 30,
            onRenderModule: function () {
                var me = this;
                me.callParent(arguments);
                me.refreshTask = Ext.TaskManager.start({
                    run: function () {
                        if (me.list && me.list.isVisible(true)) {
                            me.store.load();
                        }
                    },
                    interval: me.refreshSeconds * 1000,
                    fireOnStart: false
                });
            },
            onDestroyModule: function () {
                if (this.refreshTask) {
                    Ext.TaskManager.stop(this.refreshTask);
                }
                this.callParent(arguments);
            }
        });

        Ext.define('MBilling.view.trunkCapUsage.List', {
            extend: 'Ext.ux.grid.Panel',
            alias: 'widget.trunkcapusagelist',
            store: 'TrunkCapUsage',
            initComponent: function () {
                var me = this;
                me.allowCreate = me.allowUpdate = me.allowDelete = false;
                me.buttonUpdateLot = false;
                me.buttonCsv = false;
                me.allowPrint = false;
                me.allowSearch = false;
                me.pagination = false;
                me.filterableColumns = false;
                me.columns = [{
                    header: t('Trunk'), dataIndex: 'trunkcode', flex: 2
                }, {
                    header: t('Policy'), dataIndex: 'name', flex: 2,
                    renderer: function (v, meta, r) {
                        meta.tdAttr = 'data-qtip="' + Ext.String.htmlEncode(Ext.String.htmlEncode(r.get('summary'))) + '"';
                        return Ext.String.htmlEncode(v);
                    }
                }, {
                    header: t('Status'), dataIndex: 'state', flex: 1.4, renderer: stateRenderer
                }, {
                    header: t('Used'), dataIndex: 'pct', flex: 1.2,
                    renderer: function (v, meta, r) {
                        if (v === null) {
                            return '';
                        }
                        var width = Math.min(100, v),
                            color = v >= r.get('safety_margin_pct') ? '#c62828' : v >= 80 ? '#ef6c00' : '#2e7d32';
                        return '<div style="background:#eee;height:14px;position:relative">'
                            + '<div style="background:' + color + ';width:' + width + '%;height:14px"></div>'
                            + '<span style="position:absolute;top:0;left:4px;font-size:11px">' + v + '%</span></div>';
                    }
                }, {
                    header: t('Period'), dataIndex: 'window_start', flex: 2.6,
                    renderer: function (v, meta, r) {
                        return r.get('rolling') ? t('Rolling, since') + ' ' + v : v + ' → ' + r.get('window_end');
                    }
                }, {
                    header: t('Minutes'), dataIndex: 'billed_minutes', flex: 1.6,
                    renderer: function (v, meta, r) {
                        var text = usedOf(v, r.get('max_minutes'));
                        return r.get('reserved_minutes') ? text + ' <span style="color:#757575">(+' + r.get('reserved_minutes') + ' ' + t('reserved') + ')</span>' : text;
                    }
                }, {
                    header: t('Calls'), dataIndex: 'calls', flex: 1.2,
                    renderer: function (v, meta, r) { return usedOf(v, r.get('max_calls')); }
                }, {
                    header: t('In progress'), dataIndex: 'in_flight', flex: 1,
                    renderer: function (v, meta, r) { return usedOf(v, r.get('max_concurrent')); }
                }];
                me.callParent(arguments);
            }
        });

        Ext.define('MBilling.view.trunkCapUsage.Form', {
            extend: 'Ext.ux.form.Panel',
            alias: 'widget.trunkcapusageform',
            allowCreate: false,
            allowUpdate: false,
            initComponent: function () {
                var me = this;
                me.defaults = { xtype: 'displayfield', labelWidth: 110, anchor: '100%' };
                me.items = [
                    { name: 'trunkcode', fieldLabel: t('Trunk') },
                    { name: 'name', fieldLabel: t('Policy') },
                    { name: 'summary', fieldLabel: t('Rules'), fieldStyle: 'white-space:normal', htmlEncode: true },
                    { name: 'state', fieldLabel: t('Status'), renderer: stateRenderer },
                    { name: 'override_until', fieldLabel: t('Paused until (UTC)') }
                ];
                me.callParent(arguments);
            }
        });

        // ---- Trunk Cap Log (read-only) -----------------------------------------

        Ext.define('MBilling.view.trunkCapEvent.Module', {
            extend: 'Ext.ux.panel.Module',
            alias: 'widget.trunkcapeventmodule',
            controller: 'trunkcapevent',
            cfgEast: { flex: 0.9 }
        });

        Ext.define('MBilling.view.trunkCapEvent.Controller', {
            extend: 'Ext.ux.app.ViewController',
            alias: 'controller.trunkcapevent'
        });

        function snapshotText(json) {
            var s = Ext.decode(json || '{}', true) || {};
            if (s.limit_type === 'max_minutes') {
                return t('Minutes') + ': ' + Math.round(s.used_seconds / 60) + ' ' + t('used of') + ' '
                    + Math.round(s.effective / 60) + ' (' + t('provider cap') + ' ' + Math.round(s.limit / 60) + ')';
            }
            if (s.limit_type) {
                var labels = { max_calls: t('Calls'), max_calls_per_hour: t('Calls per hour'), max_concurrent: t('Concurrent calls'), max_cps: t('Calls per second') };
                return (labels[s.limit_type] || s.limit_type) + ': ' + s.used + ' ' + t('used of') + ' ' + s.effective
                    + (s.effective !== s.limit ? ' (' + t('provider cap') + ' ' + s.limit + ')' : '');
            }
            if (s.limit !== undefined && s.effective !== undefined) { // events written before limit_type existed
                return (s.used !== undefined ? s.used : s.used_seconds) + ' / ' + s.effective;
            }
            if (s.threshold) {
                return s.pct + '% (' + t('threshold') + ' ' + s.threshold + '%)';
            }
            if (s.before) {
                return t('was') + ' ' + s.before.calls + ' ' + t('calls') + ', ' + Math.round(s.before.billed_seconds / 60) + ' ' + t('min');
            }
            if (s.hasOwnProperty('override_until')) {
                return s.override_until ? t('paused until') + ' ' + s.override_until + ' UTC' : t('pause ended');
            }
            if (s.reservation) {
                return t('reservation') + ' ' + s.reservation + (s.cdr_seconds !== null && s.cdr_seconds !== undefined ? ', CDR ' + s.cdr_seconds + 's' : '');
            }
            return '';
        }

        Ext.define('MBilling.view.trunkCapEvent.List', {
            extend: 'Ext.ux.grid.Panel',
            alias: 'widget.trunkcapeventlist',
            store: 'TrunkCapEvent',
            fieldSearch: 'uniqueid',
            initComponent: function () {
                var me = this;
                me.allowCreate = me.allowUpdate = me.allowDelete = false;
                me.buttonUpdateLot = false;
                me.columns = [{
                    header: t('Date (UTC)'), dataIndex: 'created_at', flex: 2
                }, {
                    header: t('Trunk'), dataIndex: 'idTrunktrunkcode', flex: 2,
                    filter: { type: 'string', field: 'idTrunk.trunkcode' }
                }, {
                    header: t('Policy'), dataIndex: 'idPolicyname', flex: 2
                }, {
                    header: t('Event'), dataIndex: 'event', flex: 1.2,
                    filter: { type: 'list', options: [['blocked', 'blocked'], ['warned', 'warned'], ['reset', 'reset'], ['override', 'override'], ['swept', 'swept']] }
                }, {
                    header: t('Details'), dataIndex: 'snapshot', flex: 4, sortable: false,
                    renderer: function (v) { return Ext.String.htmlEncode(snapshotText(v)); }
                }, {
                    header: t('Call'), dataIndex: 'uniqueid', flex: 2
                }];
                me.callParent(arguments);
            }
        });

        Ext.define('MBilling.view.trunkCapEvent.Form', {
            extend: 'Ext.ux.form.Panel',
            alias: 'widget.trunkcapeventform',
            allowCreate: false,
            allowUpdate: false,
            initComponent: function () {
                var me = this;
                me.defaults = { xtype: 'displayfield', labelWidth: 90, anchor: '100%' };
                me.items = [
                    { name: 'created_at', fieldLabel: t('Date (UTC)') },
                    { name: 'event', fieldLabel: t('Event') },
                    { name: 'idTrunktrunkcode', fieldLabel: t('Trunk') },
                    { name: 'idPolicyname', fieldLabel: t('Policy') },
                    { name: 'uniqueid', fieldLabel: t('Call') },
                    { name: 'id_user', fieldLabel: t('User ID') },
                    {
                        name: 'snapshot', fieldLabel: t('Snapshot'), fieldStyle: 'white-space:pre-wrap;font-family:monospace',
                        renderer: function (v) {
                            var s = Ext.decode(v || '{}', true);
                            return Ext.String.htmlEncode(s ? JSON.stringify(s, null, 2) : v);
                        }
                    }
                ];
                me.callParent(arguments);
            }
        });

        // ---- Trunk Cap Reconciliation (provider statements) --------------------

        Ext.define('MBilling.view.trunkCapStatement.Module', {
            extend: 'Ext.ux.panel.Module',
            alias: 'widget.trunkcapstatementmodule',
            controller: 'trunkcapstatement'
        });

        Ext.define('MBilling.view.trunkCapStatement.Controller', {
            extend: 'Ext.ux.app.ViewController',
            alias: 'controller.trunkcapstatement'
        });

        Ext.define('MBilling.view.trunkCapStatement.List', {
            extend: 'Ext.ux.grid.Panel',
            alias: 'widget.trunkcapstatementlist',
            store: 'TrunkCapStatement',
            fieldSearch: 'idTrunk.trunkcode',
            initComponent: function () {
                var me = this,
                    num = function (v, d) { return v === null || v === undefined ? '' : Ext.util.Format.number(v, d || '0,000.0'); };
                me.buttonUpdateLot = false;
                me.columns = [{
                    header: t('Trunk'), dataIndex: 'idTrunktrunkcode', flex: 2,
                    filter: { type: 'string', field: 'idTrunk.trunkcode' }
                }, {
                    header: t('Period'), dataIndex: 'period_start', flex: 2.4,
                    renderer: function (v, m, r) { return Ext.Date.format(v, 'Y-m-d') + ' → ' + Ext.Date.format(r.get('period_end'), 'Y-m-d'); }
                }, {
                    header: t('Our minutes'), dataIndex: 'our_minutes', flex: 1.4, sortable: false,
                    renderer: function (v, m, r) {
                        m.tdAttr = 'data-qtip="' + t('Rounded with the provider billing increments. Unrounded:') + ' ' + num(r.get('our_real_minutes')) + '"';
                        return num(v);
                    }
                }, {
                    header: t('Provider minutes'), dataIndex: 'provider_minutes', flex: 1.4, renderer: function (v) { return num(v); }
                }, {
                    header: t('Difference'), dataIndex: 'diff_pct', flex: 1.1, sortable: false,
                    renderer: function (v) { return v === null || v === undefined ? '' : (v > 0 ? '+' : '') + Ext.util.Format.number(v, '0.00') + '%'; }
                }, {
                    header: t('Our calls'), dataIndex: 'our_calls', flex: 1, sortable: false, renderer: function (v) { return num(v, '0,000'); }
                }, {
                    header: t('Provider calls'), dataIndex: 'provider_calls', flex: 1, renderer: function (v) { return num(v, '0,000'); }
                }, {
                    header: t('Cap counter min'), dataIndex: 'counter_minutes', flex: 1.2, sortable: false, renderer: function (v) { return num(v); }
                }, {
                    header: t('Status'), dataIndex: 'status', flex: 1.2, sortable: false,
                    renderer: function (v) {
                        var map = { ok: ['#2e7d32', t('OK')], mismatch: ['#c62828', t('Mismatch')] },
                            s = map[v] || ['#757575', v || ''];
                        return '<span style="color:' + s[0] + ';font-weight:bold">' + s[1] + '</span>';
                    }
                }, {
                    header: t('Note'), dataIndex: 'note', flex: 2, hidden: window.isTablet
                }];
                me.callParent(arguments);
            }
        });

        Ext.define('MBilling.view.trunkCapStatement.Form', {
            extend: 'Ext.ux.form.Panel',
            alias: 'widget.trunkcapstatementform',
            initComponent: function () {
                var me = this;
                me.defaults = Ext.apply({}, { labelWidth: 150, anchor: '100%' }, me.defaults);
                me.items = [{
                    xtype: 'displayfield', isFormField: false, value: t('Enter the figures from the provider statement. Our minutes are computed from answered CDRs on this trunk, rounded with the provider billing increments.'),
                    fieldStyle: 'white-space:normal;color:#555'
                }, {
                    xtype: 'trunkcombo', name: 'id_trunk', fieldLabel: t('Trunk')
                }, {
                    xtype: 'datefield', name: 'period_start', fieldLabel: t('Period start'), format: 'Y-m-d', submitFormat: 'Y-m-d'
                }, {
                    xtype: 'datefield', name: 'period_end', fieldLabel: t('Period end (inclusive)'), format: 'Y-m-d', submitFormat: 'Y-m-d'
                }, {
                    name: 'timezone', fieldLabel: t('Statement timezone'), value: 'Africa/Lagos'
                }, {
                    xtype: 'numberfield', name: 'provider_minutes', fieldLabel: t('Provider minutes'), minValue: 0, decimalPrecision: 2
                }, {
                    xtype: 'numberfield', name: 'provider_calls', fieldLabel: t('Provider calls'), minValue: 0, allowDecimals: false,
                    allowBlank: true, emptyText: t('Optional')
                }, {
                    name: 'note', fieldLabel: t('Note'), allowBlank: true, maxLength: 255
                }];
                me.callParent(arguments);
            }
        });
    }

    boot();
})();
