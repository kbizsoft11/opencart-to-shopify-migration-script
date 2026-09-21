/**
 * OpenCart to Shopify Migration - Vanilla JS App
 * No frameworks - instant updates, no reactivity delays
 */

const app = {
    processId: null,
    pollInterval: null,
    stats: { total: 0, success: 0, failed: 0, warnings: 0 },
    lastLogCount: 0, // Track logs already shown to avoid duplicates
    
    // Initialize app - load defaults from .env
    async init() {
        console.log('App initializing...');
        await this.loadDefaults();
        await this.checkImageMappingStatusOnLoad();
        console.log('App ready!');
    },

    // Load default config from .env
    async loadDefaults() {
        try {
            const response = await fetch('/api/config.php');
            const data = await response.json();
            
            if (data.success && data.config) {
                // Fill form fields
                document.getElementById('db_host').value = data.config.opencart_db_host || '127.0.0.1';
                document.getElementById('db_port').value = data.config.opencart_db_port || '3306';
                document.getElementById('db_name').value = data.config.opencart_db_name || 'rachel_opencart';
                document.getElementById('db_user').value = data.config.opencart_db_user || 'root';
                document.getElementById('db_pass').value = data.config.opencart_db_pass || '';
                document.getElementById('db_prefix').value = data.config.opencart_table_prefix || 'oc_';
                document.getElementById('shopify_domain').value = data.config.shopify_store_domain || '';
                document.getElementById('shopify_token').value = data.config.shopify_admin_access_token || '';
                document.getElementById('image_url').value = data.config.opencart_public_base_url || '';
                
                console.log('✓ Loaded config from .env');
            }
        } catch (error) {
            console.error('Failed to load defaults:', error);
        }
    },

    // Get current config from form
    getConfig() {
        return {
            opencart_db_host: document.getElementById('db_host').value,
            opencart_db_port: document.getElementById('db_port').value,
            opencart_db_name: document.getElementById('db_name').value,
            opencart_db_user: document.getElementById('db_user').value,
            opencart_db_pass: document.getElementById('db_pass').value,
            opencart_table_prefix: document.getElementById('db_prefix').value,
            opencart_public_base_url: document.getElementById('image_url').value,
            shopify_store_domain: document.getElementById('shopify_domain').value,
            shopify_admin_access_token: document.getElementById('shopify_token').value,
        };
    },

    // Test database connection
    async testDatabase() {
        const btn = event.target;
        const result = document.getElementById('dbTestResult');
        
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Testing...';
        result.textContent = '';
        
        try {
            const response = await fetch('api/test-db.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(this.getConfig()),
            });
            
            const data = await response.json();
            
            if (data.success) {
                result.innerHTML = `<span class="text-green-600"><i class="fas fa-check-circle"></i> Connected! Found ${data.product_count} products</span>`;
            } else {
                result.innerHTML = `<span class="text-red-600"><i class="fas fa-times-circle"></i> ${data.message}</span>`;
            }
        } catch (error) {
            result.innerHTML = `<span class="text-red-600"><i class="fas fa-times-circle"></i> ${error.message}</span>`;
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-database"></i> Test Database Connection';
        }
    },

    // List Shopify products for debugging
    async listShopifyProducts() {
        const btn = event.target;
        
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
        
        try {
            const response = await fetch('api/list-products.php');
            const data = await response.json();
            
            if (data.success) {
                console.log('Shopify Products:', data.products);
                
                let message = `Found ${data.count} products in Shopify:\n\n`;
                data.products.forEach(product => {
                    message += `• ${product.title}\n`;
                    message += `  ID: ${product.id}\n`;
                    message += `  Handle: ${product.handle}\n`;
                    message += `  OpenCart ID: ${product.opencart_product_id || 'None'}\n\n`;
                });
                
                // Also show in console for easy copying
                console.table(data.products);
                
                alert(message);
                document.getElementById('shopifyTestResult').innerHTML = `<span class="text-blue-600"><i class="fas fa-info-circle"></i> Found ${data.count} products (see alert & console)</span>`;
            } else {
                console.error('List products error:', data);
                document.getElementById('shopifyTestResult').innerHTML = `<span class="text-red-600"><i class="fas fa-times-circle"></i> ${data.message}</span>`;
            }
        } catch (error) {
            console.error('List products network error:', error);
            document.getElementById('shopifyTestResult').innerHTML = `<span class="text-red-600"><i class="fas fa-times-circle"></i> Network error: ${error.message}</span>`;
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-list"></i> List Products';
        }
    },

    // Debug metafields and create missing definitions
    async debugMetafields() {
        const btn = event.target;
        
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Debugging...';
        
        try {
            const response = await fetch('api/metafields.php');
            const data = await response.json();
            
            if (data.success) {
                console.log('Metafield Definitions:', data.definitions);
                
                let message = `${data.message}\n\nFound ${data.definitions.length} definitions:\n\n`;
                data.definitions.forEach(def => {
                    const filterable = def.adminFilterable ? '✅ FILTERABLE' : '❌ NOT FILTERABLE';
                    message += `• ${def.namespace}.${def.key} (${def.type}) - ${filterable}\n`;
                });
                
                // Show in console for easy copying
                console.table(data.definitions);
                
                alert(message);
                document.getElementById('shopifyTestResult').innerHTML = `<span class="text-green-600"><i class="fas fa-check-circle"></i> ${data.message}</span>`;
            } else {
                console.error('Debug metafields error:', data);
                document.getElementById('shopifyTestResult').innerHTML = `<span class="text-red-600"><i class="fas fa-times-circle"></i> ${data.message}</span>`;
            }
        } catch (error) {
            console.error('Debug metafields network error:', error);
            document.getElementById('shopifyTestResult').innerHTML = `<span class="text-red-600"><i class="fas fa-times-circle"></i> Network error: ${error.message}</span>`;
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-wrench"></i> Debug Metafields';
        }
    },
    
    // Check image mapping status on app load (silent)
    async checkImageMappingStatusOnLoad() {
        try {
            const response = await fetch('api/mapping-status.php');
            const data = await response.json();
            
            if (data.success && data.csv_exists) {
                const mappingCount = data.mapping_count || 0;
                const lastUpdated = data.last_updated ? new Date(data.last_updated).toLocaleString() : 'Never';
                
                document.getElementById('imageMappingStatus').innerHTML = `
                    <div class="text-green-700">
                        <i class="fas fa-check-circle"></i> Image mapping CSV found (${mappingCount} mappings)
                    </div>
                    <div class="mt-1 text-xs">Last updated: ${lastUpdated}</div>
                `;
                document.getElementById('use_image_mapping').disabled = false;
            } else {
                document.getElementById('imageMappingStatus').innerHTML = `
                    <div class="text-gray-600">
                        <i class="fas fa-info-circle"></i> No image mapping found - run "Process Images Only" mode first
                    </div>
                `;
                document.getElementById('use_image_mapping').disabled = true;
            }
        } catch (error) {
            // Silent fail on app load
            console.log('Could not check image mapping status:', error);
        }
    },
    async checkImageMappingStatus() {
        const btn = event.target;
        const status = document.getElementById('imageMappingStatus');
        
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking...';
        
        try {
            const response = await fetch('api/mapping-status.php');
            const data = await response.json();
            
            if (data.success) {
                const csvExists = data.csv_exists;
                const mappingCount = data.mapping_count || 0;
                const lastUpdated = data.last_updated ? new Date(data.last_updated).toLocaleString() : 'Never';
                
                if (csvExists) {
                    status.innerHTML = `
                        <div class="text-green-700">
                            <i class="fas fa-check-circle"></i> Image mapping CSV found
                        </div>
                        <div class="mt-1 text-xs">
                            ${mappingCount} image mappings • Last updated: ${lastUpdated}
                        </div>
                    `;
                    document.getElementById('use_image_mapping').disabled = false;
                } else {
                    status.innerHTML = `
                        <div class="text-yellow-700">
                            <i class="fas fa-exclamation-triangle"></i> No image mapping CSV found
                        </div>
                        <div class="mt-1 text-xs">
                            Run "Process Images Only" mode first to generate mappings
                        </div>
                    `;
                    document.getElementById('use_image_mapping').disabled = true;
                    document.getElementById('use_image_mapping').checked = false;
                }
            } else {
                status.innerHTML = `<div class="text-red-700"><i class="fas fa-times-circle"></i> ${data.message}</div>`;
            }
        } catch (error) {
            status.innerHTML = `<div class="text-red-700"><i class="fas fa-times-circle"></i> Error: ${error.message}</div>`;
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-search"></i> Check Mapping Status';
        }
    },

    // Validate processed images
    async validateProcessedImages() {
        const btn = event.target;
        const status = document.getElementById('imageMappingStatus');
        
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Validating...';
        
        try {
            const response = await fetch('api/validate.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ sample_size: 20 })
            });
            const data = await response.json();
            
            if (data.success) {
                const valid = data.stats.valid;
                const total = data.stats.total;
                const percentage = total > 0 ? Math.round((valid / total) * 100) : 0;
                
                const statusClass = percentage === 100 ? 'text-green-700' : percentage >= 80 ? 'text-yellow-700' : 'text-red-700';
                const icon = percentage === 100 ? 'check-circle' : percentage >= 80 ? 'exclamation-triangle' : 'times-circle';
                
                status.innerHTML = `
                    <div class="${statusClass}">
                        <i class="fas fa-${icon}"></i> Validation: ${valid}/${total} images valid (${percentage}%)
                    </div>
                    <div class="mt-1 text-xs">
                        ${data.stats.unreachable} unreachable • ${data.stats.invalid_size} oversized • ${data.stats.invalid_megapixels} too many MP
                    </div>
                `;
            } else {
                status.innerHTML = `<div class="text-red-700"><i class="fas fa-times-circle"></i> ${data.message}</div>`;
            }
        } catch (error) {
            status.innerHTML = `<div class="text-red-700"><i class="fas fa-times-circle"></i> Error: ${error.message}</div>`;
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check-double"></i> Validate Images';
        }
    },

    // Download image mapping CSV
    async downloadImageMapping() {
        try {
            const response = await fetch('api/download-csv.php');
            
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
                
                document.getElementById('imageMappingStatus').innerHTML = `
                    <div class="text-green-700">
                        <i class="fas fa-download"></i> CSV downloaded successfully
                    </div>
                `;
            } else {
                throw new Error('Download failed');
            }
        } catch (error) {
            document.getElementById('imageMappingStatus').innerHTML = `
                <div class="text-red-700">
                    <i class="fas fa-times-circle"></i> Download failed: ${error.message}
                </div>
            `;
        }
    },
    
    // Clear test products from Shopify
    async clearShopifyProducts() {
        const btn = event.target;
        
        if (!confirm('This will DELETE all products with "opencart_product_id" metafield from your Shopify store. Continue?')) {
            return;
        }
        
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Clearing...';
        
        try {
            const response = await fetch('api/shopify-clear.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(this.getConfig()),
            });
            
            const data = await response.json();
            
            if (data.success) {
                document.getElementById('shopifyTestResult').innerHTML = `<span class="text-green-600"><i class="fas fa-check-circle"></i> Cleared ${data.deleted_count} products</span>`;
            } else {
                document.getElementById('shopifyTestResult').innerHTML = `<span class="text-red-600"><i class="fas fa-times-circle"></i> ${data.message}</span>`;
            }
        } catch (error) {
            document.getElementById('shopifyTestResult').innerHTML = `<span class="text-red-600"><i class="fas fa-times-circle"></i> ${error.message}</span>`;
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash"></i> Clear Test Products';
        }
    },
    async testShopify() {
        const btn = event.target;
        const result = document.getElementById('shopifyTestResult');
        
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Testing...';
        result.textContent = '';
        
        try {
            const response = await fetch('api/test-shopify.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(this.getConfig()),
            });
            
            const data = await response.json();
            
            if (data.success) {
                result.innerHTML = `<span class="text-green-600"><i class="fas fa-check-circle"></i> Connected to ${data.shop_name}!</span>`;
            } else {
                result.innerHTML = `<span class="text-red-600"><i class="fas fa-times-circle"></i> ${data.message}</span>`;
            }
        } catch (error) {
            result.innerHTML = `<span class="text-red-600"><i class="fas fa-times-circle"></i> ${error.message}</span>`;
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-shopping-cart"></i> Test Shopify Connection';
        }
    },

    // Start migration
    async startMigration() {
        // Reset previous state
        this.resetMigration();
        
        const config = this.getConfig();
        const mode = document.getElementById('mode').value;
        const limit = parseInt(document.getElementById('limit').value) || 0;
        
        // Validate required fields
        if (!config.opencart_db_name) {
            this.addLog('Database name is required', 'error');
            return;
        }
        
        if (mode === 'send' && (!config.shopify_store_domain || !config.shopify_admin_access_token)) {
            this.addLog('Shopify credentials are required for send mode', 'error');
            return;
        }
        
        // Show progress and console sections
        document.getElementById('progressSection').style.display = 'block';
        document.getElementById('consoleSection').style.display = 'block';
        document.getElementById('startBtn').style.display = 'none';
        document.getElementById('stopBtn').style.display = 'inline-block';
        
        // Prepare payload with correct structure
        const payload = {
            config: config,
            args: {
                mode: mode,
                limit: limit,
                offset: 0,
                verbose: document.getElementById('verbose').checked,
                update_existing: document.getElementById('update_existing').checked,
                use_image_mapping: document.getElementById('use_image_mapping').checked,
                use_image_csv: document.getElementById('use_image_csv').checked,
            }
        };
        
        this.addLog(`Starting ${mode} migration (limit: ${limit || 'all'})...`, 'info');
        
        try {
            const response = await fetch('api/migration-start.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            
            const data = await response.json();
            console.log('Start response:', data);
            
            if (data.success) {
                this.processId = data.process_id;
                this.addLog(`Migration started (Process: ${this.processId})`, 'success');
                this.startPolling();
            } else {
                const errorMsg = data.message || data.error || 'Unknown error';
                this.addLog(`Failed to start: ${errorMsg}`, 'error');
                this.stopPolling();
            }
        } catch (error) {
            this.addLog(`Network error: ${error.message}`, 'error');
            this.stopPolling();
        }
    },

    // Stop migration
    async stopMigration() {
        if (!this.processId) return;
        
        try {
            await fetch(`api/migration-stop.php?id=${this.processId}`, { method: 'POST' });
            this.addLog('Migration stopped by user', 'warning');
            this.stopPolling();
        } catch (error) {
            this.addLog(`Stop failed: ${error.message}`, 'error');
        }
    },

    // Start status polling
    startPolling() {
        // Poll every 500ms for instant updates
        this.pollInterval = setInterval(() => this.checkStatus(), 500);
    },

    // Stop polling
    stopPolling() {
        if (this.pollInterval) {
            clearInterval(this.pollInterval);
            this.pollInterval = null;
        }
        
        document.getElementById('startBtn').style.display = 'inline-block';
        document.getElementById('stopBtn').style.display = 'none';
    },
    
    // Reset migration state
    resetMigration() {
        this.processId = null;
        this.lastLogCount = 0;
        this.stats = { total: 0, success: 0, failed: 0, warnings: 0 };
        
        // Reset UI
        document.getElementById('progressSection').style.display = 'none';
        document.getElementById('consoleSection').style.display = 'none';
        document.getElementById('resultsSection').style.display = 'none';
        
        this.updateProgress({ current: 0, total: 0, percentage: 0 });
        this.updateStats(this.stats);
        this.clearConsole();
    },

    // Check migration status
    async checkStatus() {
        if (!this.processId) return;
        
        try {
            const response = await fetch(`api/migration-status.php?id=${this.processId}`);
            
            if (!response.ok) {
                console.error('Status check failed:', response.status);
                return;
            }
            
            const data = await response.json();
            
            // Handle different response formats
            if (data.status === 'not_found') {
                this.addLog('Process not found', 'error');
                this.stopPolling();
                return;
            }
            
            // Update progress from the latest progress data
            if (data.progress) {
                this.updateProgress(data.progress);
                if (data.progress.stats) {
                    this.updateStats(data.progress.stats);
                }
            }
            
            // Add recent log messages
            if (data.recent_logs && Array.isArray(data.recent_logs)) {
                data.recent_logs.forEach(logData => {
                    this.addLog(logData.message, logData.level);
                });
            }
            
            // Check status changes
            if (data.status === 'completed') {
                this.addLog('✓ Migration completed!', 'success');
                this.stopPolling();
                setTimeout(() => this.loadResults(), 1000); // Small delay to ensure files are ready
            } else if (data.status === 'failed') {
                this.addLog('✗ Migration failed!', 'error');
                this.stopPolling();
            } else if (data.status === 'stopped') {
                this.addLog('Migration was stopped', 'warning');
                this.stopPolling();
            }
            
        } catch (error) {
            console.error('Status check error:', error);
            // Don't stop polling on network errors, just log it
        }
    },

    // Update progress bar
    updateProgress(progress) {
        const current = progress.current || 0;
        const total = progress.total || 0;
        const percentage = progress.percentage || 0;
        
        document.getElementById('progressText').textContent = `${current} / ${total} products`;
        document.getElementById('progressPercent').textContent = `${percentage}%`;
        document.getElementById('progressBar').style.width = `${percentage}%`;
    },

    // Update stats
    updateStats(stats) {
        this.stats = stats;
        document.getElementById('statTotal').textContent = stats.total || 0;
        document.getElementById('statSuccess').textContent = stats.success || 0;
        document.getElementById('statFailed').textContent = stats.failed || 0;
        document.getElementById('statWarnings').textContent = stats.warnings || 0;
    },

    // Add log to console
    addLog(message, level = 'info') {
        const console = document.getElementById('console');
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

    // Clear console
    clearConsole() {
        document.getElementById('console').innerHTML = '';
    },

    // Load results
    async loadResults() {
        if (!this.processId) return;
        
        try {
            const response = await fetch(`api/migration-results.php?id=${this.processId}`);
            const data = await response.json();
            
            if (data.reports) {
                const select = document.getElementById('reportSelect');
                select.innerHTML = '<option value="">-- Select Report --</option>';
                
                Object.keys(data.reports).forEach(key => {
                    const option = document.createElement('option');
                    option.value = key;
                    option.textContent = `${key.replace(/_/g, ' ').toUpperCase()} (${data.reports[key].rows} rows)`;
                    select.appendChild(option);
                });
                
                document.getElementById('resultsSection').style.display = 'block';
            }
        } catch (error) {
            console.error('Load results error:', error);
        }
    },

    // Load specific report
    async loadReport() {
        const reportType = document.getElementById('reportSelect').value;
        if (!reportType || !this.processId) return;
        
        try {
            const response = await fetch(`api/migration-report.php?id=${this.processId}&type=${reportType}&limit=1000`);
            const data = await response.json();
            
            if (data.success && data.data.length > 0) {
                this.renderTable(data.data);
            }
        } catch (error) {
            console.error('Load report error:', error);
        }
    },

    // Render table with pagination support
    renderTable(data) {
        if (!data || data.length === 0) {
            document.getElementById('reportContainer').innerHTML = '<p class="text-gray-500">No data available</p>';
            return;
        }
        
        const headers = Object.keys(data[0]);
        let html = `
            <div class="mb-4 text-sm text-gray-600">
                Showing ${data.length} records
            </div>
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>`;
        
        headers.forEach(header => {
            const displayName = header.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            html += `<th class="px-4 py-2 text-left font-medium text-gray-900">${displayName}</th>`;
        });
        
        html += `
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">`;
        
        // Show only first 100 rows to prevent browser freeze
        const displayData = data.slice(0, 100);
        
        displayData.forEach((row, index) => {
            html += `<tr class="${index % 2 === 0 ? 'bg-white' : 'bg-gray-50'}">`;
            headers.forEach(header => {
                let value = row[header] || '';
                
                // Truncate long values
                if (typeof value === 'string' && value.length > 100) {
                    value = value.substring(0, 100) + '...';
                }
                
                // Handle URLs
                if (typeof value === 'string' && (value.startsWith('http://') || value.startsWith('https://'))) {
                    value = `<a href="${value}" target="_blank" class="text-sky-600 hover:underline">View</a>`;
                }
                
                html += `<td class="px-4 py-2 text-gray-900">${value}</td>`;
            });
            html += '</tr>';
        });
        
        html += '</tbody></table>';
        
        if (data.length > 100) {
            html += `<div class="mt-4 text-sm text-gray-600">Showing first 100 of ${data.length} records. Download CSV for full data.</div>`;
        }
        
        document.getElementById('reportContainer').innerHTML = html;
    },

    // Escape HTML
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    },

    // Refresh page data
    refreshPage() {
        location.reload();
    }
};

// Initialize app when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    app.init();
});
