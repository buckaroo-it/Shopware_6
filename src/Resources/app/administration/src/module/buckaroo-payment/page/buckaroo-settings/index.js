const { Component, Mixin } = Shopware;

import template from './buckaroo-settings.html.twig';
import './style.scss';

Component.register('buckaroo-settings', {
    template,

    mixins: [
        Mixin.getByName('notification'),
        Mixin.getByName('sw-inline-snippet')
    ],

    inject: [ 'BuckarooPaymentSettingsService' ],

    data() {
        return {
            isLoading: false,
            isSaveSuccessful: false,
            config: {},
            websiteKeyIdFilled: false,
            secretKeyIdFilled: false,
            showValidationErrors: false,
            phpversion: false,
            isSupportModalOpen: false,
            isPhpVersionSupport: false
        };
    },

    created() {
        var that = this;
        this.createdComponent();

        this.BuckarooPaymentSettingsService.getSupportVersion()
        .then((result) => {
            that.phpversion = result.phpversion;
            that.isPhpVersionSupport = result.isPhpVersionSupport;
        });
    },

    computed: {
        credentialsMissing: function() {
            return !this.websiteKeyIdFilled || !this.secretKeyIdFilled;
        }
    },

    metaInfo() {
        return {
            title: this.$createTitle()
        };
    },

    methods: {
        createdComponent() {
            var me = this;
        },

        saveFinish() {
            this.isSaveSuccessful = false;
        },

        onConfigChange(config) {
            this.config = config;

            this.checkCredentialsFilled();

            this.showValidationErrors = false;
        },

        checkCredentialsFilled() {
            this.websiteKeyIdFilled = !!this.getConfigValue('websiteKey');
            this.secretKeyIdFilled = !!this.getConfigValue('secretKey');
        },

        getConfigValue(field) {
            const defaultConfig = this.$refs.systemConfig.actualConfigData.null;
            const salesChannelId = this.$refs.systemConfig.currentSalesChannelId;

            if (salesChannelId === null) {
                return this.config[`BuckarooPayment.config.${field}`];
            }
            return this.config[`BuckarooPayment.config.${field}`]
                    || defaultConfig[`BuckarooPayment.config.${field}`];
        },

        getPaymentConfigValue(field, prefix) {
            let uppercasedField = field.charAt(0).toUpperCase() + field.slice(1);

            return this.getConfigValue(prefix + uppercasedField)
                || this.getConfigValue(field);
        },

        onSave() {
            if (this.credentialsMissing) {
                this.showValidationErrors = true;
                return;
            }

            this.isSaveSuccessful = false;
            this.isLoading = true;
            this.$refs.systemConfig.saveAll().then(() => {
                this.isLoading = false;
                this.isSaveSuccessful = true;
            }).catch(() => {
                this.isLoading = false;
            });
        },

        getBind(element, config) {
            if (config !== this.config) {
                this.onConfigChange(config);
            }
            if (this.showValidationErrors) {
                if (element.name === 'BuckarooPayment.config.websiteKey' && !this.websiteKeyIdFilled) {
                    element.config.error = {
                        code: 1,
                        detail: this.$tc('buckaroo-payment.messageNotBlank')
                    };
                }
                if (element.name === 'BuckarooPayment.config.secretKey' && !this.websiteKeyIdFilled) {
                    element.config.error = {
                        code: 1,
                        detail: this.$tc('buckaroo-payment.messageNotBlank')
                    };
                }
            }

            return element;
        }
    }
});
