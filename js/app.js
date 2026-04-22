const app = {
    currentView: null,
    viewContainer: null,
    
    init() {
        this.viewContainer = document.getElementById('view-container');
        window.addEventListener('popstate', () => this.handleRouting());
        this.handleRouting();
    },

    navigate(path) {
        window.history.pushState({}, '', path);
        this.handleRouting();
    },

    handleRouting() {
        const path = window.location.pathname;
        if (path === '/' || path === '') {
            this.renderCreateView();
        } else if (path.startsWith('/c/')) {
            const id = path.split('/')[2];
            this.renderClipboardView(id);
        } else if (path === '/admin') {
            this.renderAdminView();
        } else {
            this.viewContainer.innerHTML = '<h1>404 Not Found</h1>';
        }
    },

    async renderCreateView() {
        const template = document.getElementById('view-create');
        const clone = template.content.cloneNode(true);
        this.viewContainer.innerHTML = '';
        this.viewContainer.appendChild(clone);

        const btnCreate = document.getElementById('btn-create');
        const contentArea = document.getElementById('content');
        const passwordGroup = document.getElementById('create-password-group');

        // Check if write password is required
        try {
            const res = await fetch('/api.php?action=config');
            const config = await res.json();
            if (config.writepwd) {
                passwordGroup.classList.remove('hidden');
            }
        } catch (e) {}

        btnCreate.onclick = async () => {
            const data = {
                content: contentArea.value,
                expires: document.getElementById('expires').value,
                editable: document.getElementById('editable').checked,
                one_shot: document.getElementById('one_shot').checked,
                custom_id: document.getElementById('custom_id').value || null,
                password: document.getElementById('write-password')?.value || null
            };

            try {
                const res = await fetch('/api.php?action=create', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const result = await res.json();

                if (result.success) {
                    document.getElementById('create-result').classList.remove('hidden');
                    document.getElementById('result-url').value = window.location.origin + result.url;
                    document.getElementById('btn-copy').onclick = () => {
                        navigator.clipboard.writeText(window.location.origin + result.url);
                        alert('URL copied to clipboard!');
                    };
                    document.getElementById('btn-go').onclick = () => this.navigate(result.url);
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (err) {
                alert('An error occurred.');
            }
        };
    },

    async renderClipboardView(id, password = null) {
        const template = document.getElementById('view-clipboard');
        const clone = template.content.cloneNode(true);
        this.viewContainer.innerHTML = '';
        this.viewContainer.appendChild(clone);

        const contentContainer = document.getElementById('clipboard-content-container');
        const passwordPrompt = document.getElementById('clipboard-password-prompt');
        const readPasswordInput = document.getElementById('read-password');
        const btnUnlock = document.getElementById('btn-read-unlock');

        document.getElementById('clipboard-id').textContent = id;
        const textArea = document.getElementById('clipboard-content');
        const saveStatus = document.getElementById('save-status');

        try {
            const url = `/api.php?action=get&id=${id}` + (password ? `&password=${encodeURIComponent(password)}` : '');
            const res = await fetch(url);
            const data = await res.json();

            if (data.needs_password) {
                contentContainer.classList.add('hidden');
                passwordPrompt.classList.remove('hidden');
                btnUnlock.onclick = () => {
                    this.renderClipboardView(id, readPasswordInput.value);
                };
                return;
            }

            if (data.error) {
                textArea.value = data.error;
                return;
            }

            passwordPrompt.classList.add('hidden');
            contentContainer.classList.remove('hidden');

            textArea.value = data.content;
            if (data.editable) {
                textArea.readOnly = false;
                document.getElementById('tag-editable').classList.remove('hidden');

                // Auto-save logic
                let timeout = null;
                textArea.oninput = () => {
                    saveStatus.textContent = 'Typing...';
                    clearTimeout(timeout);
                    timeout = setTimeout(async () => {
                        saveStatus.textContent = 'Saving...';
                        try {
                            const res = await fetch('/api.php?action=update', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ id, content: textArea.value, password })
                            });
                            const result = await res.json();
                            if (result.success) {
                                saveStatus.textContent = 'Saved.';
                            } else {
                                saveStatus.textContent = 'Error saving: ' + result.error;
                            }
                        } catch (err) {
                            saveStatus.textContent = 'Error saving.';
                        }
                    }, 500);
                };
            }

            if (data.is_one_shot) {
                document.getElementById('tag-oneshot').classList.remove('hidden');
            }
            if (data.expires_at) {
                const tag = document.getElementById('tag-expires');
                tag.textContent = 'Expires: ' + new Date(data.expires_at).toLocaleString();
                tag.classList.remove('hidden');
            }

        } catch (err) {
            textArea.value = 'Failed to load clipboard.';
        }
    },

    async renderAdminView() {
        const template = document.getElementById('view-admin');
        const clone = template.content.cloneNode(true);
        this.viewContainer.innerHTML = '';
        this.viewContainer.appendChild(clone);

        const loginForm = document.getElementById('admin-login');
        const dashboard = document.getElementById('admin-dashboard');
        const searchInput = document.getElementById('admin-search');

        let searchTimeout = null;
        searchInput.oninput = () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => loadDashboard(searchInput.value), 300);
        };

        const loadDashboard = async (search = '') => {
            const res = await fetch(`/api.php?action=admin&search=${encodeURIComponent(search)}`);
            const data = await res.json();

            if (data.needs_login) {
                loginForm.classList.remove('hidden');
                document.getElementById('btn-login').onclick = async () => {
                    const password = document.getElementById('admin-password').value;
                    const loginRes = await fetch('/api.php?action=admin', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ login_password: password })
                    });
                    const loginData = await loginRes.json();
                    if (loginData.success) {
                        loginForm.classList.add('hidden');
                        loadDashboard();
                    } else {
                        alert('Invalid password');
                    }
                };
            } else if (data.clipboards) {
                dashboard.classList.remove('hidden');
                const tbody = document.querySelector('#clipboards-table tbody');
                tbody.innerHTML = '';
                data.clipboards.forEach(cb => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td><a href="/c/${cb.id}" onclick="event.preventDefault(); app.navigate('/c/${cb.id}')">${cb.id}</a></td>
                        <td>${cb.created_at}</td>
                        <td>${cb.expires_at || 'Never'}</td>
                        <td>${cb.access_count}</td>
                        <td>
                            <button class="btn-text btn-edit" data-id="${cb.id}">Edit</button>
                            <button class="btn-text btn-extend" data-id="${cb.id}">Extend</button>
                            <button class="btn-text btn-delete" data-id="${cb.id}">Delete</button>
                        </td>
                    `;
                    tbody.appendChild(tr);
                });

                document.querySelectorAll('.btn-edit').forEach(btn => {
                    btn.onclick = async () => {
                        const id = btn.getAttribute('data-id');
                        const cb = data.clipboards.find(c => c.id === id);
                        const newContent = prompt('Edit content:', cb.content);
                        if (newContent !== null) {
                            const res = await fetch('/api.php?action=admin_edit', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ id, content: newContent })
                            });
                            const result = await res.json();
                            if (result.success) loadDashboard(searchInput.value);
                        }
                    };
                });

                document.querySelectorAll('.btn-extend').forEach(btn => {
                    btn.onclick = async () => {
                        const id = btn.getAttribute('data-id');
                        const days = prompt('Extend by how many days?', '7');
                        if (days !== null) {
                            const res = await fetch('/api.php?action=admin_extend', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ id, days })
                            });
                            const result = await res.json();
                            if (result.success) loadDashboard(searchInput.value);
                        }
                    };
                });

                document.querySelectorAll('.btn-delete').forEach(btn => {
                    btn.onclick = async () => {
                        const id = btn.getAttribute('data-id');
                        if (confirm(`Delete clipboard ${id}?`)) {
                            const res = await fetch(`/api.php?action=delete&id=${id}`, { method: 'POST' });
                            const result = await res.json();
                            if (result.success) {
                                loadDashboard();
                            }
                        }
                    };
                });
            }
        };

        loadDashboard();
    }
};

window.app = app;
document.addEventListener('DOMContentLoaded', () => app.init());
