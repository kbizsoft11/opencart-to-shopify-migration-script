/**
 * Image Processing Center - Dedicated JavaScript App
 */

const app = {
    currentTab: 'overview',
    processId: null,
    pollInterval: null,
    consolePaused: false,
    
    // Current pagination state
    mappingPagination: {
        currentPage: 1,
        perPage: 50,
        totalPages: 0,
        totalRecords: 0
    },

    // Initialize the application
    async init() {
        console.log('Image Processing App initializing...');
        await this.loadConfiguration();
        await this.checkStatus();
        
        // Setup event listeners for new options
        this.setupProcessingOptionListeners();
        
        console.log('Image Processing App ready!');
    },

    // Setup listeners for processing options
    setupProcessingOptionListeners() {
        const processingModeSelect = document.getElementById('processingMode');
        const productStatusFilter = document.getElementById('productStatusFilter');
        const modeInfoBox = document.getElementById('modeInfoBox');
        
        if (processingModeSelect) {
            processingModeSelect.addEventListener('change', (e) => {
                const mode = e.target.value;
                const modeInfo = {
                    'resume': {
                        title: '📋 Resume Mode',
                        items: [
                            '✓ Skips images already in CSV (by Original_Image_URL)',
                            '✓ Appends new rows to end of CSV',
                            '✓ Fastest option - continues from last position',
                            '✓ Safe - won\'t re-process existing images'
                        ],
                        color: 'green'
                    },
                    'fresh': {
                        title: '🔄 Fresh Start Mode',
                        items: [
                            '⚠ Deletes existing CSV file',
                            '⚠ Starts processing from scratch',
                            '⚠ Slower - processes all selected products',
                            '⚠ Will re-process previously processed images',
                            '⚠ Use when you need to regenerate mappings'
                        ],
                        color: 'yellow'
                    }
                };
                
                const info = modeInfo[mode];
                const colorClass = info.color === 'green' ? 'bg-green-50 border-green-200' : 'bg-yellow-50 border-yellow-200';
                const textColor = info.color === 'green' ? 'text-green-900' : 'text-yellow-900';
                const itemColor = info.color === 'green' ? 'text-green-800' : 'text-yellow-800';
                
                modeInfoBox.innerHTML = `
                    <h4 class="font-medium ${textColor} mb-2">${info.title}</h4>
                    <ul class="text-xs ${itemColor} space-y-1">
                        ${info.items.map(item => `<li>${item}</li>`).join('')}
                    </ul>
                `;
                modeInfoBox.className = `mb-4 p-3 rounded-lg border ${colorClass}`;
            });
        }
    },

    // Switch between tabs
    switchTab(tabName) {
        // Update tab buttons
        document.querySelectorAll('.tab-content').forEach(tab => {
            tab.style.display = 'none';
        });
        document.querySelectorAll('[id^="tab-"]').forEach(btn => {
            btn.className = btn.className.replace('tab-active', 'tab-inactive');
        });
        
        // Show selected tab
        document.getElementById(`content-${tabName}`).style.display = 'block';
        document.getElementById(`tab-${tabName}`).className = 
            document.getElementById(`tab-${tabName}`).className.replace('tab-inactive', 'tab-active');
        
        this.currentTab = tabName;
        
        // Load tab-specific data
        this.loadTabData(tabName);
    },

    // Load configuration from backend
    async loadConfiguration() {
        try {
            const response = await fetch('/api/config.php');
            const data = await response.json();
            
            if (data.success && data.config) {
                // Fill configuration fields
                const imageUrl = data.config.opencart_public_base_url || '';
                document.getElementById('openCartImageUrl').value = imageUrl;
                
                // Load environment variables
                const imgbbKey = localStorage.getItem('imgbb_api_key') || '';
                document.getElementById('imgbbKey').value = imgbbKey;
                
                console.log('✓ Configuration loaded');
            }
        } catch (error) {
            console.error('Failed to load configuration:', error);
            this.showNotification('Failed to load configuration', 'error');
        }
    },

    // Check overall status  
    async checkStatus() {
        try {
            this.showNotification('Refreshing status...', 'info');
            
            // Check database status
            await this.checkDatabaseStatus();
            
            // Check image mapping status  
            await this.checkImageMappingStatus();
            
            // Check for recent processing activity
            await this.checkRecentActivity();
            
            this.showNotification('Status updated successfully', 'success');
        } catch (error) {
            console.error('Status check failed:', error);
            this.showNotification('Status check failed', 'error');
        }
    },

    // Check database connectivity
    async checkDatabaseStatus() {
        try {
            const response = await fetch('/api/test-db.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(await this.getConfig())
            });
            
            const data = await response.json();
            const statusElement = document.getElementById('dbStatus');
            
            if (data.success) {
                statusElement.textContent = 'Connected';
                statusElement.className = 'text-xl font-bold text-green-600';
            } else {
                statusElement.textContent = 'Error';
                statusElement.className = 'text-xl font-bold text-red-600';
            }
        } catch (error) {
            const statusElement = document.getElementById('dbStatus');
            statusElement.textContent = 'Unknown';
            statusElement.className = 'text-xl font-bold text-gray-600';
        }
    },

    // Check image mapping status
    async checkImageMappingStatus() {
        try {
            const response = await fetch('/api/mapping-status.php');
            const data = await response.json();
            
            if (data.success) {
                const countElement = document.getElementById('mappingCount');
                const lastElement = document.getElementById('lastProcessing');
                
                countElement.textContent = data.mapping_count || 0;
                
                if (data.csv_exists && data.last_updated) {
                    const lastDate = new Date(data.last_updated);
                    lastElement.textContent = lastDate.toLocaleString();
                } else {
                    lastElement.textContent = 'Never';
                }
            }
        } catch (error) {
            console.error('Failed to check mapping status:', error);
        }
    },

    // Check for recent processing activity
    async checkRecentActivity() {
        try {
            const logPath = 'output/image_processing/image_processing.log';
            
            // Try to get info about recent processing from the log file
            // This is a simple implementation - could be enhanced with a dedicated API endpoint
            const response = await fetch('/api/images/mapping-status');
            const data = await response.json();
            
            if (data.success && data.last_updated) {
                const lastDate = new Date(data.last_updated);
                const now = new Date();
                const diffHours = (now - lastDate) / (1000 * 60 * 60);
                
                let activityText = 'Never';
                if (diffHours < 1) {
                    activityText = `${Math.round(diffHours * 60)} minutes ago`;
                } else if (diffHours < 24) {
                    activityText = `${Math.round(diffHours)} hours ago`;
                } else {
                    activityText = `${Math.round(diffHours / 24)} days ago`;
                }
                
                document.getElementById('lastProcessing').textContent = activityText;
            }
        } catch (error) {
            console.error('Failed to check recent activity:', error);
        }
    },
    // Get current configuration
    async getConfig() {
        const response = await fetch('/api/config.php');
        const data = await response.json();
        return data.success ? data.config : {};
    },

    // Quick start processing
    async quickStart() {
        document.getElementById('processLimit').value = 50;
        document.getElementById('resumeProcessing').checked = true;
        this.switchTab('process');
        setTimeout(() => this.startProcessing(), 500);
    },

    // Start image processing
    async startProcessing() {
        const limit = parseInt(document.getElementById('processLimit').value) || 0;
        const productId = parseInt(document.getElementById('processProductId').value) || null;
        
        // Get new options
        const statusFilter = document.getElementById('productStatusFilter').value;
        const processingMode = document.getElementById('processingMode').value;
        
        // Convert resume checkbox state based on processing mode
        const resume = processingMode === 'resume';
        
        // Prepare configuration
        const config = await this.getConfig();
        const imageUrl = document.getElementById('openCartImageUrl').value;
        if (imageUrl) {
            config.opencart_public_base_url = imageUrl;
        }

        // Add status filter to config
        config.product_status_filter = statusFilter; // 'all', 'active', 'inactive'

        const payload = {
            config: config,
            args: {
                mode: 'process-images',
                limit: limit,
                product_id: productId,
                resume: resume,
                status_filter: statusFilter,  // Pass to backend
                verbose: true,
                format: 'jsonl'
            }
        };

        // Show confirmation if doing fresh start
        if (processingMode === 'fresh') {
            const confirmed = confirm(
                '⚠️ FRESH START MODE\n\n' +
                'This will:\n' +
                '• Delete the existing CSV file\n' +
                '• Start processing from scratch\n' +
                '• Re-process ALL selected products\n\n' +
                'This is slower but ensures a clean mapping.\n\n' +
                'Continue?'
            );
            if (!confirmed) {
                this.addProcessLog('Fresh start cancelled by user', 'warning');
                return;
            }
        }

        this.showProcessingUI(true);
        this.addProcessLog(`Starting image processing (${statusFilter} products, ${processingMode} mode)...`, 'info');

        try {
            const response = await fetch('/api/migration-start.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const data = await response.json();
            
            if (data.success) {
                this.processId = data.process_id;
                this.addProcessLog(`✓ Processing started (Process: ${this.processId})`, 'success');
                this.startPolling();
            } else {
                this.addProcessLog(`✗ Failed to start: ${data.message || data.error}`, 'error');
                this.showProcessingUI(false);
            }
        } catch (error) {
            this.addProcessLog(`✗ Network error: ${error.message}`, 'error');
            this.showProcessingUI(false);
        }
    },

    // Stop processing
    async stopProcessing() {
        if (!this.processId) return;

        try {
            await fetch(`/api/migration-stop.php?id=${this.processId}`, { method: 'POST' });
            this.addProcessLog('Processing stopped by user', 'warning');
            this.stopPolling();
        } catch (error) {
            this.addProcessLog(`Stop failed: ${error.message}`, 'error');
        }
    },

    // Show/hide processing UI
    showProcessingUI(show) {
        const progressSection = document.getElementById('processingProgress');
        const consoleSection = document.getElementById('processConsoleSection');
        const startBtn = document.getElementById('startProcessBtn');
        const stopBtn = document.getElementById('stopProcessBtn');

        if (show) {
            progressSection.style.display = 'block';
            consoleSection.style.display = 'block';
            startBtn.style.display = 'none';
            stopBtn.style.display = 'inline-block';
        } else {
            startBtn.style.display = 'inline-block';
            stopBtn.style.display = 'none';
        }
    },
    // Start status polling
    startPolling() {
        this.pollInterval = setInterval(() => this.checkProcessStatus(), 500);
    },

    // Stop polling
    stopPolling() {
        if (this.pollInterval) {
            clearInterval(this.pollInterval);
            this.pollInterval = null;
        }
        this.showProcessingUI(false);
    },

    // Check process status
    async checkProcessStatus() {
        if (!this.processId) return;

        try {
            const response = await fetch(`/api/migration-status.php?id=${this.processId}`);
            if (!response.ok) return;

            const data = await response.json();

            if (data.status === 'not_found') {
                this.addProcessLog('Process not found', 'error');
                this.stopPolling();
                return;
            }

            // Update progress
            if (data.progress) {
                this.updateProcessProgress(data.progress);
            }

            // Add recent logs
            if (data.recent_logs && Array.isArray(data.recent_logs)) {
                data.recent_logs.forEach(logData => {
                    this.addProcessLog(logData.message, logData.level);
                });
            }

            // Handle completion
            if (data.status === 'completed') {
                this.addProcessLog('✓ Image processing completed!', 'success');
                this.stopPolling();
                setTimeout(() => this.checkStatus(), 1000);
            } else if (data.status === 'failed') {
                this.addProcessLog('✗ Image processing failed!', 'error');
                this.stopPolling();
            }

        } catch (error) {
            console.error('Status check error:', error);
        }
    },

    // Update processing progress
    updateProcessProgress(progress) {
        const current = progress.current || 0;
        const total = progress.total || 0;
        const percentage = progress.percentage || 0;

        document.getElementById('processProgressText').textContent = `${current} / ${total} products`;
        document.getElementById('processProgressPercent').textContent = `${percentage}%`;
        document.getElementById('processProgressBar').style.width = `${percentage}%`;

        // Update stats if available
        if (progress.stats) {
            document.getElementById('processStatTotal').textContent = progress.stats.total_images || 0;
            document.getElementById('processStatOversized').textContent = progress.stats.oversized_images || 0;
            document.getElementById('processStatProcessed').textContent = progress.stats.processed_images || 0;
            document.getElementById('processStatUploaded').textContent = progress.stats.uploaded_images || 0;
        }
    },

    // Add log to processing console
    addProcessLog(message, level = 'info') {
        if (this.consolePaused) return;

        const console = document.getElementById('processConsole');
        const line = document.createElement('div');
        line.className = `log-line log-${level}`;

        const icon = {
            success: '✓',
            error: '✗',
            warning: '⚠',
            info: '➜'
        }[level] || '•';

        line.textContent = `${icon} ${message}`;
        console.appendChild(line);

        // Auto-scroll to bottom
        console.scrollTop = console.scrollHeight;

        // Keep only last 200 lines
        if (console.children.length > 200) {
            console.removeChild(console.firstChild);
        }
    },
    // Validate images
    async validateImages() {
        const sampleSize = parseInt(document.getElementById('validationSampleSize').value) || 20;
        const validationType = document.getElementById('validationType').value;

        this.showNotification('Starting image validation...', 'info');

        try {
            const config = await this.getConfig();
            
            const response = await fetch('/api/validate.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    config: config,
                    sample_size: sampleSize,
                    validation_type: validationType 
                })
            });

            const data = await response.json();

            if (data.success) {
                this.displayValidationResults(data.stats);
                this.updateValidationScore(data.stats);
                this.showNotification(`Validation complete: ${data.stats.valid || 0}/${data.stats.total || 0} images valid`, 'success');
            } else {
                this.showNotification(`Validation failed: ${data.message || 'Unknown error'}`, 'error');
            }
        } catch (error) {
            this.showNotification(`Validation error: ${error.message}`, 'error');
        }
    },

    // Display validation results
    displayValidationResults(stats) {
        document.getElementById('validationResults').style.display = 'block';
        
        document.getElementById('validResultValid').textContent = stats.valid || 0;
        document.getElementById('validResultInvalid').textContent = 
            (stats.invalid_size || 0) + (stats.invalid_megapixels || 0) + (stats.other_errors || 0);
        document.getElementById('validResultUnreachable').textContent = stats.unreachable || 0;
        document.getElementById('validResultOversized').textContent = stats.invalid_size || 0;
        
        const successRate = stats.total > 0 ? Math.round((stats.valid / stats.total) * 100) : 0;
        document.getElementById('validResultScore').textContent = `${successRate}%`;
    },

    // Update validation score in overview
    updateValidationScore(stats) {
        const successRate = stats.total > 0 ? Math.round((stats.valid / stats.total) * 100) : 0;
        document.getElementById('validationScore').textContent = `${successRate}%`;
        
        const element = document.getElementById('validationScore');
        if (successRate >= 95) {
            element.className = 'text-xl font-bold text-green-600';
        } else if (successRate >= 80) {
            element.className = 'text-xl font-bold text-yellow-600';
        } else {
            element.className = 'text-xl font-bold text-red-600';
        }
    },

    // Download mapping CSV
    async downloadMapping() {
        try {
            const response = await fetch('/api/download-csv.php');
            
            if (response.ok) {
                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.style.display = 'none';
                a.href = url;
                a.download = 'image_mapping.csv';
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);
                
                this.showNotification('CSV downloaded successfully', 'success');
            } else {
                throw new Error('Download failed');
            }
        } catch (error) {
            this.showNotification(`Download failed: ${error.message}`, 'error');
        }
    },

    // Download report (alias for consistency)
    downloadReport() {
        this.downloadMapping();
    },
    // Preview mapping data with pagination
    async previewMapping(page = 1, perPage = null) {
        try {
            const pageSize = perPage || parseInt(document.getElementById('mappingPageSize').value) || 50;
            const offset = (page - 1) * pageSize;
            
            const response = await fetch(`/api/mapping-preview.php?limit=${pageSize}&offset=${offset}`);
            const data = await response.json();

            if (data.success && data.mappings) {
                this.mappingPagination = {
                    currentPage: data.pagination.current_page,
                    perPage: data.pagination.per_page,
                    totalPages: data.pagination.total_pages,
                    totalRecords: data.pagination.total_records
                };
                
                this.renderMappingTable(data.mappings);
                this.renderPaginationControls();
                this.showNotification(`Loaded ${data.mappings.length} of ${data.pagination.total_records} mapping entries`, 'success');
            } else {
                document.getElementById('mappingPreviewContainer').innerHTML = `
                    <div class="text-center text-gray-500 py-8">
                        <i class="fas fa-exclamation-triangle text-4xl mb-2"></i>
                        <p>No mapping data available</p>
                        <p class="text-sm mt-2">Run image processing first to generate mappings</p>
                    </div>
                `;
            }
        } catch (error) {
            this.showNotification(`Failed to load mapping preview: ${error.message}`, 'error');
        }
    },

    // Render mapping table with pagination
    renderMappingTable(mappings) {
        if (!mappings || mappings.length === 0) {
            document.getElementById('mappingPreviewContainer').innerHTML = `
                <div class="text-center text-gray-500 py-8">
                    <i class="fas fa-table text-4xl mb-2"></i>
                    <p>No mapping data available</p>
                </div>
            `;
            return;
        }

        let html = `
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium text-gray-900">Product ID</th>
                            <th class="px-4 py-2 text-left font-medium text-gray-900">Variant ID</th>
                            <th class="px-4 py-2 text-left font-medium text-gray-900">Original URL</th>
                            <th class="px-4 py-2 text-left font-medium text-gray-900">Final URL</th>
                            <th class="px-4 py-2 text-left font-medium text-gray-900">Size</th>
                            <th class="px-4 py-2 text-left font-medium text-gray-900">Processed</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
        `;

        mappings.forEach((mapping, index) => {
            const rowClass = index % 2 === 0 ? 'bg-white' : 'bg-gray-50';
            const processedBadge = mapping.processed === 'Yes' 
                ? '<span class="px-2 py-1 bg-green-100 text-green-800 rounded-full text-xs">Processed</span>'
                : '<span class="px-2 py-1 bg-gray-100 text-gray-800 rounded-full text-xs">Original</span>';

            html += `
                <tr class="${rowClass} hover:bg-gray-100">
                    <td class="px-4 py-2 font-medium">${mapping.product_id}</td>
                    <td class="px-4 py-2">${mapping.variant_id || '-'}</td>
                    <td class="px-4 py-2">
                        <a href="${mapping.original_url}" target="_blank" class="text-blue-600 hover:underline text-xs">
                            ${this.truncateUrl(mapping.original_url)}
                        </a>
                    </td>
                    <td class="px-4 py-2">
                        <a href="${mapping.final_url}" target="_blank" class="text-green-600 hover:underline text-xs">
                            ${this.truncateUrl(mapping.final_url)}
                        </a>
                    </td>
                    <td class="px-4 py-2 text-xs">${mapping.final_size || '-'}</td>
                    <td class="px-4 py-2">${processedBadge}</td>
                </tr>
            `;
        });

        html += `
                    </tbody>
                </table>
            </div>
            <div id="paginationControls"></div>
        `;
        
        document.getElementById('mappingPreviewContainer').innerHTML = html;
    },

    // Render pagination controls
    renderPaginationControls() {
        const { currentPage, totalPages, totalRecords, perPage } = this.mappingPagination;
        
        if (totalPages <= 1) return;

        const startRecord = ((currentPage - 1) * perPage) + 1;
        const endRecord = Math.min(currentPage * perPage, totalRecords);
        
        let html = `
            <div class="flex items-center justify-between py-4 px-4 bg-gray-50 border-t">
                <div class="text-sm text-gray-700">
                    Showing <span class="font-medium">${startRecord}</span> to <span class="font-medium">${endRecord}</span> 
                    of <span class="font-medium">${totalRecords}</span> results
                </div>
                <div class="flex items-center space-x-1">
        `;

        // Previous button
        if (currentPage > 1) {
            html += `
                <button onclick="app.previewMapping(${currentPage - 1})" 
                        class="px-3 py-2 text-sm bg-white border border-gray-300 text-gray-500 hover:text-gray-700 rounded-md">
                    Previous
                </button>
            `;
        }

        // Page numbers
        const startPage = Math.max(1, currentPage - 2);
        const endPage = Math.min(totalPages, currentPage + 2);

        if (startPage > 1) {
            html += `<button onclick="app.previewMapping(1)" class="px-3 py-2 text-sm bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 rounded-md">1</button>`;
            if (startPage > 2) {
                html += `<span class="px-3 py-2 text-sm text-gray-500">...</span>`;
            }
        }

        for (let page = startPage; page <= endPage; page++) {
            if (page === currentPage) {
                html += `<span class="px-3 py-2 text-sm bg-blue-600 text-white rounded-md">${page}</span>`;
            } else {
                html += `<button onclick="app.previewMapping(${page})" class="px-3 py-2 text-sm bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 rounded-md">${page}</button>`;
            }
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                html += `<span class="px-3 py-2 text-sm text-gray-500">...</span>`;
            }
            html += `<button onclick="app.previewMapping(${totalPages})" class="px-3 py-2 text-sm bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 rounded-md">${totalPages}</button>`;
        }

        // Next button
        if (currentPage < totalPages) {
            html += `
                <button onclick="app.previewMapping(${currentPage + 1})" 
                        class="px-3 py-2 text-sm bg-white border border-gray-300 text-gray-500 hover:text-gray-700 rounded-md">
                    Next
                </button>
            `;
        }

        html += '</div></div>';
        
        document.getElementById('paginationControls').innerHTML = html;
    },

    // Utility functions
    truncateUrl(url, maxLength = 50) {
        if (!url) return '-';
        return url.length > maxLength ? url.substring(0, maxLength) + '...' : url;
    },

    // Load tab-specific data
    loadTabData(tabName) {
        switch (tabName) {
            case 'overview':
                this.checkStatus();
                break;
            case 'validate':
                // Load any existing validation results
                break;
            case 'manage':
                this.updateMappingInfo();
                break;
        }
    },

    // Update mapping file info
    async updateMappingInfo() {
        try {
            const response = await fetch('/api/mapping-status.php');
            const data = await response.json();

            if (data.success && data.csv_exists) {
                document.getElementById('mappingFileSize').textContent = 
                    data.file_size ? this.formatBytes(data.file_size) : '-';
                document.getElementById('mappingTotalCount').textContent = data.mapping_count || 0;
                document.getElementById('mappingLastUpdate').textContent = 
                    data.last_updated ? new Date(data.last_updated).toLocaleString() : '-';
            } else {
                document.getElementById('mappingFileSize').textContent = '-';
                document.getElementById('mappingTotalCount').textContent = '0';
                document.getElementById('mappingLastUpdate').textContent = 'Never';
            }
        } catch (error) {
            console.error('Failed to update mapping info:', error);
        }
    },
    // Utility functions
    formatBytes(bytes) {
        const units = ['B', 'KB', 'MB', 'GB'];
        for (let i = 0; bytes > 1024 && i < units.length - 1; i++) {
            bytes /= 1024;
        }
        return Math.round(bytes * 100) / 100 + ' ' + units[i];
    },

    // Show notification
    showNotification(message, type = 'info') {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `fixed top-4 right-4 p-4 rounded-lg shadow-lg z-50 ${this.getNotificationClass(type)}`;
        notification.innerHTML = `
            <div class="flex items-center">
                <i class="fas ${this.getNotificationIcon(type)} mr-2"></i>
                <span>${message}</span>
                <button onclick="this.parentElement.parentElement.remove()" class="ml-4 text-white hover:text-gray-200">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;
        
        document.body.appendChild(notification);
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (notification.parentElement) {
                notification.remove();
            }
        }, 5000);
    },

    getNotificationClass(type) {
        const classes = {
            info: 'bg-blue-500 text-white',
            success: 'bg-green-500 text-white',
            warning: 'bg-yellow-500 text-white',
            error: 'bg-red-500 text-white'
        };
        return classes[type] || classes.info;
    },

    getNotificationIcon(type) {
        const icons = {
            info: 'fa-info-circle',
            success: 'fa-check-circle',
            warning: 'fa-exclamation-triangle',
            error: 'fa-times-circle'
        };
        return icons[type] || icons.info;
    },

    // Console controls
    pauseConsole() {
        this.consolePaused = !this.consolePaused;
        const btn = document.getElementById('pauseConsoleBtn');
        if (this.consolePaused) {
            btn.innerHTML = '<i class="fas fa-play"></i> Resume';
            btn.className = btn.className.replace('bg-yellow-600', 'bg-green-600');
        } else {
            btn.innerHTML = '<i class="fas fa-pause"></i> Pause';
            btn.className = btn.className.replace('bg-green-600', 'bg-yellow-600');
        }
    },

    clearConsole(type = 'process') {
        const console = document.getElementById(`${type}Console`);
        if (console) {
            console.innerHTML = '';
        }
    },

    // Refresh page data
    refreshPage() {
        this.checkStatus();
        this.loadTabData(this.currentTab);
        this.showNotification('Page data refreshed', 'success');
    },

    // Placeholder functions for management actions
    async backupMapping() {
        this.showNotification('Backup functionality coming soon', 'info');
    },

    async clearMapping() {
        if (confirm('Are you sure you want to clear all image mappings? This cannot be undone.')) {
            this.showNotification('Clear mapping functionality coming soon', 'warning');
        }
    },

    async importMapping() {
        this.showNotification('Import functionality coming soon', 'info');
    },

    refreshMappingPreview() {
        this.previewMapping();
    },

    refreshProcessingLog() {
        this.showNotification('Processing log refresh coming soon', 'info');
    },

    updateMappingView() {
        const newPerPage = parseInt(document.getElementById('mappingPageSize').value) || 50;
        this.mappingPagination.perPage = newPerPage;
        this.previewMapping(1, newPerPage); // Reset to page 1 with new page size
    }
};

// Initialize app when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    app.init();
});