<script type="text/ecmascript-6">
    import $ from 'jquery';
    import _ from 'lodash';
    import axios from 'axios';

    export default {
        props: [
            'resource', 'title', 'showAllFamily', 'hideSearch'
        ],


        /**
         * The component's data.
         */
        data() {
            return {
                tag: '',
                familyHash: '',
                entries: [],
                ready: false,
                recordingStatus: 'enabled',
                lastEntryIndex: '',
                hasMoreEntries: true,
                hasNewEntries: false,
                entriesPerRequest: 50,
                loadingNewEntries: false,
                loadingMoreEntries: false,

                updateTimeAgoTimeout: null,

                newEntriesTimeout: null,
                newEntriesTimer: 2500,

                updateEntriesTimeout: null,
                updateEntriesTimer: 2500,

                // Service selection
                selectedService: '',
                showServiceSelector: false,
                serviceSelectionRequired: false,
                staticServices: [
                    'builder',
                    'search',
                    'cart',
                    'admin',
                    'user',
                    'b2b'
                ],

                // DateTime range selection
                fromDateTime: '',
                toDateTime: '',
                showDateTimeSelector: false,
                maxHourDuration: 1, // Maximum 1 hour selection

                // Request cancellation
                loadEntriesCancelToken: null,
                checkNewEntriesCancelToken: null,
                updateEntriesCancelToken: null
            };
        },


        /**
         * Prepare the component.
         */
        mounted() {
            document.title = this.title + " - Telescope";

            this.familyHash = this.$route.query.family_hash || '';
            this.tag = this.$route.query.tag || '';
            this.selectedService = this.$route.query.service || this.getCurrentService();

            // Initialize datetime range from URL params if present
            this.fromDateTime = this.$route.query.from_datetime || '';
            this.toDateTime = this.$route.query.to_datetime || '';

            this.loadEntries((entries) => {
                this.entries = entries;

                this.checkForNewEntries();

                this.ready = true;
            });

            this.updateEntries();

            this.updateTimeAgo();

            this.focusOnSearch();
        },


        /**
         * Clean before the component is destroyed.
         */
        beforeDestroy() {
            this.cancelAllRequests();
        },

        /**
         * Clean after the component is destroyed.
         */
        destroyed() {
            clearTimeout(this.newEntriesTimeout);
            clearTimeout(this.updateEntriesTimeout);
            clearTimeout(this.updateTimeAgoTimeout);

            this.cancelAllRequests();

            document.onkeyup = null;
        },


        watch: {
            '$route.query': function () {
                clearTimeout(this.newEntriesTimeout);

                this.hasNewEntries = false;
                this.lastEntryIndex = '';

                if (!this.$route.query.family_hash) {
                    this.familyHash = '';
                }

                if (!this.$route.query.tag) {
                    this.tag = '';
                }

                this.ready = false;

                this.loadEntries((entries) => {
                    this.entries = entries;

                    this.checkForNewEntries();

                    this.ready = true;
                });
            },
        },


        methods: {
            loadEntries(after){
                // Cancel any existing loadEntries request
                if (this.loadEntriesCancelToken) {
                    this.loadEntriesCancelToken.cancel('Operation cancelled due to new request');
                }
                
                // Create new cancel token
                this.loadEntriesCancelToken = axios.CancelToken.source();
                
                let url = Telescope.basePath + '/telescope-api/' + this.resource +
                    '?tag=' + this.tag +
                    '&before=' + this.lastEntryIndex +
                    '&take=' + this.entriesPerRequest +
                    '&family_hash=' + this.familyHash +
                    '&service=' + this.selectedService;
                
                // Only add datetime parameters if they are set
                if (this.fromDateTime && this.toDateTime) {
                    url += '&from_datetime=' + encodeURIComponent(this.fromDateTime) +
                           '&to_datetime=' + encodeURIComponent(this.toDateTime);
                }
                
                axios.post(url, {}, {
                    cancelToken: this.loadEntriesCancelToken.token
                }).then(response => {
                    this.lastEntryIndex = response.data.entries.length ? _.last(response.data.entries).sequence : this.lastEntryIndex;

                    this.hasMoreEntries = response.data.entries.length >= this.entriesPerRequest;

                    this.recordingStatus = response.data.status;

                    if (_.isFunction(after)) {
                        after(
                                this.familyHash || this.showAllFamily ? response.data.entries : _.uniqBy(response.data.entries, entry => entry.family_hash || _.uniqueId())
                        );
                    }
                }).catch(error => {
                    // Don't handle cancelled requests
                    if (axios.isCancel(error)) {
                        return;
                    }
                    
                    if (error.response && error.response.status === 422) {
                        // Service selection required
                        this.serviceSelectionRequired = true;
                        this.showServiceSelector = true;
                        this.ready = true;
                    } else {
                        // Other error
                        this.$root.alert.type = 'error';
                        this.$root.alert.message = error.response?.data?.message || 'An error occurred while loading entries.';
                    }
                }).finally(() => {
                    // Clear the cancel token since request is complete
                    this.loadEntriesCancelToken = null;
                })
            },


            /**
             * Keep checking if there are new entries.
             */
            checkForNewEntries(){
                // Don't check for new entries if service selection is required
                if (this.serviceSelectionRequired || !this.selectedService) {
                    return;
                }
                
                // Don't poll for new entries when datetime filter is active (viewing historical data)
                if (this.fromDateTime && this.toDateTime) {
                    return;
                }
                
                this.newEntriesTimeout = setTimeout(() => {
                    // Cancel any existing checkNewEntries request
                    if (this.checkNewEntriesCancelToken) {
                        this.checkNewEntriesCancelToken.cancel('Operation cancelled due to new request');
                    }
                    
                    // Create new cancel token
                    this.checkNewEntriesCancelToken = axios.CancelToken.source();
                    
                    let url = Telescope.basePath + '/telescope-api/' + this.resource +
                        '?tag=' + this.tag +
                        '&take=1' +
                        '&family_hash=' + this.familyHash +
                        '&service=' + this.selectedService;
                    
                    axios.post(url, {}, {
                        cancelToken: this.checkNewEntriesCancelToken.token
                    }).then(response => {
                        if (! this._isDestroyed) {
                            this.recordingStatus = response.data.status;

                            if (response.data.entries.length && !this.entries.length) {
                                this.loadNewEntries();
                            } else if (response.data.entries.length && _.first(response.data.entries).id !== _.first(this.entries).id) {
                                if (this.$root.autoLoadsNewEntries) {
                                    this.loadNewEntries();
                                } else {
                                    this.hasNewEntries = true;
                                }
                            } else {
                                this.checkForNewEntries();
                            }
                        }
                    }).catch(error => {
                        // Don't handle cancelled requests
                        if (axios.isCancel(error)) {
                            return;
                        }
                        
                        // Continue checking even if there's an error
                        if (!this._isDestroyed) {
                            this.checkForNewEntries();
                        }
                    }).finally(() => {
                        // Clear the cancel token since request is complete
                        this.checkNewEntriesCancelToken = null;
                    })
                }, this.newEntriesTimer);
            },


            /**
             * Update the timeago of each entry.
             */
            updateTimeAgo(){
                this.updateTimeAgoTimeout = setTimeout(() => {
                    _.each($('[data-timeago]'), time => {
                        $(time).html(this.timeAgo($(time).data('timeago')));
                    });

                    this.updateTimeAgo();
                }, 60000)
            },


            /**
             * Search the entries of this type.
             */
            search(){
                this.debouncer(() => {
                    this.hasNewEntries = false;
                    this.lastEntryIndex = '';

                    clearTimeout(this.newEntriesTimeout);

                    this.$router.push({query: _.assign({}, this.$route.query, {tag: this.tag})});
                });
            },


            /**
             * Load more entries.
             */
            loadOlderEntries(){
                this.loadingMoreEntries = true;

                this.loadEntries((entries) => {
                    this.entries.push(...entries);

                    this.loadingMoreEntries = false;
                });
            },


            /**
             * Load new entries.
             */
            loadNewEntries(){
                this.hasMoreEntries = true;
                this.hasNewEntries = false;
                this.lastEntryIndex = '';
                this.loadingNewEntries = true;

                clearTimeout(this.newEntriesTimeout);

                this.loadEntries((entries) => {
                    this.entries = entries;

                    this.loadingNewEntries = false;

                    this.checkForNewEntries();
                });
            },


            /**
             * Update the existing entries if needed.
             */
            updateEntries(){
                if (this.resource !== 'jobs') return;

                this.updateEntriesTimeout = setTimeout(() => {
                    let uuids = _.chain(this.entries).filter(entry => entry.content.status === 'pending').map('id').value();

                    if (uuids.length) {
                        // Cancel any existing updateEntries request
                        if (this.updateEntriesCancelToken) {
                            this.updateEntriesCancelToken.cancel('Operation cancelled due to new request');
                        }
                        
                        // Create new cancel token
                        this.updateEntriesCancelToken = axios.CancelToken.source();
                        
                        axios.post(Telescope.basePath + '/telescope-api/' + this.resource, {
                            uuids: uuids
                        }, {
                            cancelToken: this.updateEntriesCancelToken.token
                        }).then(response => {
                            this.recordingStatus = response.data.status;

                            this.entries = _.map(this.entries, entry => {
                                if (!_.includes(uuids, entry.id)) return entry;

                                return _.find(response.data.entries, {id: entry.id});
                            });
                        }).catch(error => {
                            // Don't handle cancelled requests
                            if (axios.isCancel(error)) {
                                return;
                            }
                            // Silently continue on error
                        }).finally(() => {
                            // Clear the cancel token since request is complete
                            this.updateEntriesCancelToken = null;
                        })
                    }

                    this.updateEntries();
                }, this.updateEntriesTimer);
            },


            /**
             * Focus on the search input when "/" key is hit.
             */
            focusOnSearch(){
                document.onkeyup = event => {
                    if (event.which === 191 || event.keyCode === 191) {
                        let searchInput = document.getElementById("searchInput");

                        if (searchInput) {
                            searchInput.focus();
                        }
                    }
                };
            },

            /**
             * Select a service and reload entries
             */
            selectService(service) {
                this.selectedService = service;
                this.serviceSelectionRequired = false;
                this.showServiceSelector = false;
                
                // Save to localStorage for persistence
                localStorage.setItem('telescope_selected_service', service);
                
                // Update URL
                this.$router.push({
                    query: { ...this.$route.query, service: service }
                });
                
                // Reset and reload entries
                this.entries = [];
                this.ready = false;
                this.lastEntryIndex = '';
                this.hasMoreEntries = true;
                
                this.loadEntries((entries) => {
                    this.entries = entries;
                    this.checkForNewEntries();
                    this.ready = true;
                });
            },

            /**
             * Show service selector
             */
            showServiceSelection() {
                this.showServiceSelector = true;
            },

            /**
             * Hide service selector
             */
            hideServiceSelection() {
                this.showServiceSelector = false;
                if (!this.selectedService) {
                    this.serviceSelectionRequired = true;
                }
            },



            /**
             * Round date to nearest 5-minute interval
             */
            roundToFiveMinutes(date) {
                const roundedDate = new Date(date);
                const minutes = roundedDate.getMinutes();
                const roundedMinutes = Math.floor(minutes / 5) * 5;
                roundedDate.setMinutes(roundedMinutes, 0, 0); // Set seconds and milliseconds to 0
                return roundedDate;
            },

            /**
             * Format date for datetime-local input
             */
            formatDateTimeLocal(date) {
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                const hours = String(date.getHours()).padStart(2, '0');
                const minutes = String(date.getMinutes()).padStart(2, '0');
                
                return `${year}-${month}-${day}T${hours}:${minutes}`;
            },

            /**
             * Toggle datetime selector visibility
             */
            toggleDateTimeSelector() {
                this.showDateTimeSelector = !this.showDateTimeSelector;
                
                // If opening the selector and no datetime is set, populate with defaults
                if (this.showDateTimeSelector && (!this.fromDateTime || !this.toDateTime)) {
                    this.setDefaultDateTimeRange();
                }
            },

            /**
             * Validate datetime range (max 1 hour, 5-minute intervals)
             */
            validateDateTimeRange() {
                if (!this.fromDateTime || !this.toDateTime) {
                    return false;
                }

                const fromDate = new Date(this.fromDateTime);
                const toDate = new Date(this.toDateTime);
                
                // Check if dates are aligned to 5-minute intervals
                if (fromDate.getMinutes() % 5 !== 0 || fromDate.getSeconds() !== 0) {
                    alert('Start time must be aligned to 5-minute intervals (e.g., 10:00, 10:05, 10:10).');
                    return false;
                }
                
                if (toDate.getMinutes() % 5 !== 0 || toDate.getSeconds() !== 0) {
                    alert('End time must be aligned to 5-minute intervals (e.g., 10:00, 10:05, 10:10).');
                    return false;
                }

                const diffInHours = (toDate - fromDate) / (1000 * 60 * 60);

                if (diffInHours > this.maxHourDuration) {
                    alert(`Maximum time range is ${this.maxHourDuration} hour(s). Please select a shorter range.`);
                    return false;
                }

                if (diffInHours <= 0) {
                    alert('End time must be after start time.');
                    return false;
                }

                return true;
            },

            /**
             * Apply datetime range and reload entries
             */
            applyDateTimeRange() {
                if (!this.validateDateTimeRange()) {
                    return;
                }

                this.showDateTimeSelector = false;
                
                // Update URL with datetime parameters
                this.$router.push({
                    query: {
                        ...this.$route.query,
                        from_datetime: this.fromDateTime,
                        to_datetime: this.toDateTime
                    }
                });
                
                // Reset and reload entries with new datetime range
                this.entries = [];
                this.ready = false;
                this.lastEntryIndex = '';
                this.hasMoreEntries = true;
                
                this.loadEntries((entries) => {
                    this.entries = entries;
                    this.checkForNewEntries(); // This will not poll since datetime is set
                    this.ready = true;
                });
            },

            /**
             * Round fromDateTime to 5-minute intervals when user changes it
             */
            roundFromDateTime() {
                if (this.fromDateTime) {
                    const date = new Date(this.fromDateTime);
                    this.fromDateTime = this.formatDateTimeLocal(this.roundToFiveMinutes(date));
                }
            },

            /**
             * Round toDateTime to 5-minute intervals when user changes it
             */
            roundToDateTime() {
                if (this.toDateTime) {
                    const date = new Date(this.toDateTime);
                    this.toDateTime = this.formatDateTimeLocal(this.roundToFiveMinutes(date));
                }
            },

            /**
             * Get formatted display text for current datetime range
             */
            getDateTimeRangeDisplay() {
                if (!this.fromDateTime || !this.toDateTime) {
                    return 'Live Monitoring (Last 30 mins)';
                }

                const fromDate = new Date(this.fromDateTime);
                const toDate = new Date(this.toDateTime);
                
                const fromStr = fromDate.toLocaleString('en-US', { 
                    month: 'short', 
                    day: 'numeric', 
                    hour: '2-digit', 
                    minute: '2-digit' 
                });
                const toStr = toDate.toLocaleString('en-US', { 
                    month: 'short', 
                    day: 'numeric', 
                    hour: '2-digit', 
                    minute: '2-digit' 
                });
                
                return `${fromStr} - ${toStr}`;
            },

            /**
             * Clear datetime filter and return to live monitoring
             */
            clearDateTimeFilter() {
                this.fromDateTime = '';
                this.toDateTime = '';
                this.showDateTimeSelector = false;
                
                // Update URL to remove datetime parameters
                this.$router.push({
                    query: {
                        ...this.$route.query,
                        from_datetime: undefined,
                        to_datetime: undefined
                    }
                });
                
                // Reset and reload entries for live monitoring
                this.entries = [];
                this.ready = false;
                this.lastEntryIndex = '';
                this.hasMoreEntries = true;
                
                this.loadEntries((entries) => {
                    this.entries = entries;
                    this.checkForNewEntries(); // Resume live monitoring
                    this.ready = true;
                });
            },

            /**
             * Initialize default datetime range for the selector (not applied by default)
             */
            setDefaultDateTimeRange() {
                const now = new Date();
                const thirtyMinutesAgo = new Date(now.getTime() - 30 * 60 * 1000);
                
                // Set default values in the form but don't apply them
                this.fromDateTime = this.formatDateTimeLocal(this.roundToFiveMinutes(thirtyMinutesAgo));
                this.toDateTime = this.formatDateTimeLocal(this.roundToFiveMinutes(now));
            },

            /**
             * Cancel all pending requests
             */
            cancelAllRequests() {
                if (this.loadEntriesCancelToken) {
                    this.loadEntriesCancelToken.cancel('Component navigation/destroy');
                    this.loadEntriesCancelToken = null;
                }
                if (this.checkNewEntriesCancelToken) {
                    this.checkNewEntriesCancelToken.cancel('Component navigation/destroy');
                    this.checkNewEntriesCancelToken = null;
                }
                if (this.updateEntriesCancelToken) {
                    this.updateEntriesCancelToken.cancel('Component navigation/destroy');
                    this.updateEntriesCancelToken = null;
                }
            }
        }
    }
</script>

<template>
    <div class="card overflow-hidden">
        <div class="card-header d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center">
                <h2 class="h6 m-0 mr-3">{{this.title}}</h2>
                
                <!-- Service Selector -->
                <div class="dropdown" v-if="selectedService || showServiceSelector">
                    <button class="btn btn-sm btn-primary dropdown-toggle" type="button" @click="showServiceSelection">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon" style="width: 14px; height: 14px;" fill="currentColor">
                            <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd" />
                        </svg>
                        <span style="color: white;">{{ selectedService || 'Select Service' }}</span>
                    </button>
                </div>
            </div>

            <div class="d-flex justify-content-center flex-grow-1 align-items-center">
                <!-- DateTime Range Selector -->
                <div class="dropdown mr-2">
                    <button class="btn btn-sm dropdown-toggle" 
                            type="button" 
                            @click="toggleDateTimeSelector"
                            :class="fromDateTime && toDateTime ? 'btn-primary' : 'btn-outline-secondary'">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon mr-1" style="width: 14px; height: 14px;" fill="currentColor">
                            <path fill-rule="evenodd" d="M5.75 2a.75.75 0 01.75.75V4h7V2.75a.75.75 0 011.5 0V4h.25A2.75 2.75 0 0118 6.75v8.5A2.75 2.75 0 0115.25 18H4.75A2.75 2.75 0 012 15.25v-8.5A2.75 2.75 0 014.75 4H5V2.75A.75.75 0 015.75 2zm-1 5.5c-.69 0-1.25.56-1.25 1.25v6.5c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25v-6.5c0-.69-.56-1.25-1.25-1.25H4.75z" clip-rule="evenodd" />
                        </svg>
                        {{ getDateTimeRangeDisplay() }}
                    </button>
                    
                    <!-- DateTime Range Dropdown -->
                    <div v-if="showDateTimeSelector" class="dropdown-menu show" style="position: absolute; top: 100%; left: 50%; transform: translateX(-50%); z-index: 1050; min-width: 300px;">
                        <div class="p-3">
                            <h6 class="mb-3">Select Time Range (Max 1 hour)</h6>
                            
                            <div class="form-group mb-3">
                                <label for="fromDateTime" class="form-label">From (5-min intervals):</label>
                                <input type="datetime-local" 
                                       id="fromDateTime" 
                                       class="form-control" 
                                       v-model="fromDateTime"
                                       step="300"
                                       @change="roundFromDateTime">
                            </div>
                            
                            <div class="form-group mb-3">
                                <label for="toDateTime" class="form-label">To (5-min intervals):</label>
                                <input type="datetime-local" 
                                       id="toDateTime" 
                                       class="form-control" 
                                       v-model="toDateTime"
                                       step="300"
                                       @change="roundToDateTime">
                            </div>
                            
                            <div class="d-flex justify-content-between">
                                <button class="btn btn-sm btn-secondary" @click="showDateTimeSelector = false">
                                    Cancel
                                </button>
                                <div>
                                    <button class="btn btn-sm btn-warning mr-2" 
                                            @click="clearDateTimeFilter"
                                            v-if="fromDateTime && toDateTime">
                                        Clear Filter
                                    </button>
                                    <button class="btn btn-sm btn-primary" @click="applyDateTimeRange">
                                        Apply Filter
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Clear Filter Button (when filter is active) -->
                <button class="btn btn-sm btn-outline-warning" 
                        @click="clearDateTimeFilter"
                        v-if="fromDateTime && toDateTime"
                        title="Return to live monitoring">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon" style="width: 14px; height: 14px;" fill="currentColor">
                        <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" />
                    </svg>
                </button>
            </div>

            <div class="form-control-with-icon w-25" v-if="!hideSearch && (tag || entries.length > 0) && !serviceSelectionRequired">
                <div class="icon-wrapper">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon" fill="currentColor">
                        <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd" />
                    </svg>
                </div>
                <input type="text" class="form-control w-100"
                       id="searchInput"
                       placeholder="Search Tag" v-model="tag" @input.stop="search">
            </div>
        </div>

        <p v-if="recordingStatus !== 'enabled'" class="mt-0 mb-0 disabled-watcher d-flex align-items-center">
            <svg xmlns="http://www.w3.org/2000/svg" x="0px" y="0px" width="20px" height="20px" viewBox="0 0 90 90" class="mr-2">
                <path fill="#FFFFFF" d="M45 0C20.1 0 0 20.1 0 45s20.1 45 45 45 45-20.1 45-45S69.9 0 45 0zM45 74.5c-3.6 0-6.5-2.9-6.5-6.5s2.9-6.5 6.5-6.5 6.5 2.9 6.5 6.5S48.6 74.5 45 74.5zM52.1 23.9l-2.5 29.6c0 2.5-2.1 4.6-4.6 4.6 -2.5 0-4.6-2.1-4.6-4.6l-2.5-29.6c-0.1-0.4-0.1-0.7-0.1-1.1 0-4 3.2-7.2 7.2-7.2 4 0 7.2 3.2 7.2 7.2C52.2 23.1 52.2 23.5 52.1 23.9z"></path>
            </svg>
            <span class="ml-1" v-if="recordingStatus == 'disabled'">Telescope is currently disabled.</span>
            <span class="ml-1" v-if="recordingStatus == 'paused'">Telescope recording is paused.</span>
            <span class="ml-1" v-if="recordingStatus == 'off'">This watcher is turned off.</span>
        </p>

        <!-- Service Selection Required -->
        <div v-if="serviceSelectionRequired" class="d-flex flex-column align-items-center justify-content-center card-bg-secondary p-5 bottom-radius">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="fill-text-color mb-3" style="width: 48px; height: 48px;">
                <path fill-rule="evenodd" d="M8.25 6.75a3.75 3.75 0 117.5 0 3.75 3.75 0 01-7.5 0zM15.75 9.75a3 3 0 116 0 3 3 0 01-6 0zM2.25 9.75a3 3 0 116 0 3 3 0 01-6 0zM6.31 15.117A6.745 6.745 0 0112 12a6.745 6.745 0 016.709 7.498.75.75 0 01-.372.568A12.696 12.696 0 0112 21.75c-2.305 0-4.47-.612-6.337-1.684a.75.75 0 01-.372-.568 6.787 6.787 0 011.019-1.381z" clip-rule="evenodd" />
            </svg>
            
            <h3 class="h5 mb-3">Service Selection Required</h3>
            <p class="text-muted mb-4 text-center">Please select a service to view telescope entries. This helps organize logs across your microservices architecture.</p>
            
            <div class="row g-2 w-100" style="max-width: 600px;">
                <div v-for="service in staticServices" :key="service" class="col-md-6 col-lg-4">
                    <button 
                        @click="selectService(service)" 
                        class="btn btn-outline-primary w-100 d-flex align-items-center justify-content-start p-3 service-btn"
                        style="border-radius: 8px; transition: all 0.2s ease;">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon mr-2" style="width: 16px; height: 16px;">
                            <path fill-rule="evenodd" d="M4.25 2A2.25 2.25 0 002 4.25v2.5A2.25 2.25 0 004.25 9h2.5A2.25 2.25 0 009 6.75v-2.5A2.25 2.25 0 006.75 2h-2.5zm0 9A2.25 2.25 0 002 13.25v2.5A2.25 2.25 0 004.25 18h2.5A2.25 2.25 0 009 15.75v-2.5A2.25 2.25 0 006.75 11h-2.5zm9-9A2.25 2.25 0 0011 4.25v2.5A2.25 2.25 0 0013.25 9h2.5A2.25 2.25 0 0018 6.75v-2.5A2.25 2.25 0 0015.75 2h-2.5zm0 9A2.25 2.25 0 0011 13.25v2.5A2.25 2.25 0 0013.25 18h2.5A2.25 2.25 0 0018 15.75v-2.5A2.25 2.25 0 0015.75 11h-2.5z" clip-rule="evenodd" />
                        </svg>
                        {{ service }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Service Selection Dropdown -->
        <div v-if="showServiceSelector && !serviceSelectionRequired" class="position-absolute bg-white border rounded shadow-lg p-3" style="top: 60px; left: 20px; z-index: 1000; min-width: 250px;">
            <h6 class="mb-2">Switch Service</h6>
            <div class="list-group list-group-flush">
                <button 
                    v-for="service in staticServices" 
                    :key="service"
                    @click="selectService(service)"
                    class="list-group-item list-group-item-action d-flex align-items-center"
                    :class="{'active': service === selectedService}">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon mr-2" style="width: 14px; height: 14px;">
                        <path fill-rule="evenodd" d="M4.25 2A2.25 2.25 0 002 4.25v2.5A2.25 2.25 0 004.25 9h2.5A2.25 2.25 0 009 6.75v-2.5A2.25 2.25 0 006.75 2h-2.5zm0 9A2.25 2.25 0 002 13.25v2.5A2.25 2.25 0 004.25 18h2.5A2.25 2.25 0 009 15.75v-2.5A2.25 2.25 0 006.75 11h-2.5zm9-9A2.25 2.25 0 0011 4.25v2.5A2.25 2.25 0 0013.25 9h2.5A2.25 2.25 0 0018 6.75v-2.5A2.25 2.25 0 0015.75 2h-2.5zm0 9A2.25 2.25 0 0011 13.25v2.5A2.25 2.25 0 0013.25 18h2.5A2.25 2.25 0 0018 15.75v-2.5A2.25 2.25 0 0015.75 11h-2.5z" clip-rule="evenodd" />
                    </svg>
                    {{ service }}
                </button>
            </div>
            <button @click="hideServiceSelection" class="btn btn-sm btn-outline-secondary mt-2 w-100">Close</button>
        </div>

        <div v-if="!ready" class="d-flex align-items-center justify-content-center card-bg-secondary p-5 bottom-radius">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" class="icon spin mr-2 fill-text-color">
                <path d="M12 10a2 2 0 0 1-3.41 1.41A2 2 0 0 1 10 8V0a9.97 9.97 0 0 1 10 10h-8zm7.9 1.41A10 10 0 1 1 8.59.1v2.03a8 8 0 1 0 9.29 9.29h2.02zm-4.07 0a6 6 0 1 1-7.25-7.25v2.1a3.99 3.99 0 0 0-1.4 6.57 4 4 0 0 0 6.56-1.42h2.1z"></path>
            </svg>

            <span>Scanning...</span>
        </div>


        <div v-if="ready && entries.length == 0" class="d-flex flex-column align-items-center justify-content-center card-bg-secondary p-5 bottom-radius">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 60 60" class="fill-text-color" style="width: 200px;">
                <path fill-rule="evenodd" d="M7 10h41a11 11 0 0 1 0 22h-8a3 3 0 0 0 0 6h6a6 6 0 1 1 0 12H10a4 4 0 1 1 0-8h2a2 2 0 1 0 0-4H7a5 5 0 0 1 0-10h3a3 3 0 0 0 0-6H7a6 6 0 1 1 0-12zm14 19a1 1 0 0 1-1-1 1 1 0 0 0-2 0 1 1 0 0 1-1 1 1 1 0 0 0 0 2 1 1 0 0 1 1 1 1 1 0 0 0 2 0 1 1 0 0 1 1-1 1 1 0 0 0 0-2zm-5.5-11a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zm24 10a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zm1 18a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zm-14-3a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zm22-23a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zM33 18a1 1 0 0 1-1-1v-1a1 1 0 0 0-2 0v1a1 1 0 0 1-1 1h-1a1 1 0 0 0 0 2h1a1 1 0 0 1 1 1v1a1 1 0 0 0 2 0v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 0-2h-1z"></path>
            </svg>

            <span>We didn't find anything - just empty space.</span>
        </div>


        <table id="indexScreen" class="table table-hover mb-0 penultimate-column-right" v-if="ready && entries.length > 0">
            <thead>
            <slot name="table-header"></slot>
            </thead>


            <transition-group tag="tbody" name="list">
                <tr v-if="hasNewEntries" key="newEntries" class="dontanimate">
                    <td colspan="100" class="text-center card-bg-secondary py-2">
                        <small><a href="#" v-on:click.prevent="loadNewEntries" v-if="!loadingNewEntries">Load New Entries</a></small>

                        <small v-if="loadingNewEntries">Loading...</small>
                    </td>
                </tr>


                <tr v-for="entry in entries" :key="entry.id">
                    <slot name="row" :entry="entry"></slot>
                </tr>


                <tr v-if="hasMoreEntries" key="olderEntries" class="dontanimate">
                    <td colspan="100" class="text-center card-bg-secondary py-2">
                        <small><a href="#" v-on:click.prevent="loadOlderEntries" v-if="!loadingMoreEntries">Load Older Entries</a></small>

                        <small v-if="loadingMoreEntries">Loading...</small>
                    </td>
                </tr>
            </transition-group>
        </table>

    </div>
</template>

<style scoped>
.service-btn:hover {
    background-color: #007bff !important;
    color: white !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,123,255,0.3);
}

.service-btn {
    transition: all 0.2s ease;
    border: 2px solid #dee2e6;
}

.service-btn:focus {
    box-shadow: 0 0 0 0.2rem rgba(0,123,255,0.25);
}

.position-absolute {
    backdrop-filter: blur(10px);
}

@media (prefers-color-scheme: dark) {
    .position-absolute {
        background-color: #2d3748 !important;
        border-color: #4a5568 !important;
    }
}
</style>
