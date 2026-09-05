/**
 * Dynamic Dropdown System
 * 
 * Manages bidirectional dependencies between:
 * - Subject Name dropdown (now the primary selector)
 * - Year Level dropdown
 * - Strand dropdown
 * 
 * Features:
 * - Forward: Subject selection → auto-populates Year Level + Strand
 * - Reverse: Year Level + Strand selected → Subject dropdown filters
 * - Caches API data for performance
 * - Prevents mismatched selections
 * - Real-time validation
 */

class DynamicDropdownSystem {
    constructor(config = {}) {
        this.apiUrl = config.apiUrl || '/capstone/api/get_subjects_data.php';
        this.yearLevelSelectId = config.yearLevelSelectId || 'year_level';
        this.strandSelectId = config.strandSelectId || 'strand';
        this.subjectSelectId = config.subjectSelectId || 'subject_name';
        this.enableCache = config.enableCache !== false;

        this.subjectsData = [];
        this.cache = new Map();
        this.isInitialized = false;

        this.init();
    }

    async init() {
        try {
            console.log('[DynamicDropdown] Initializing...');
            
            await this.loadSubjectsData();
            
            this.yearLevelSelect = document.getElementById(this.yearLevelSelectId);
            this.strandSelect = document.getElementById(this.strandSelectId);
            this.subjectSelect = document.getElementById(this.subjectSelectId);

            if (!this.yearLevelSelect || !this.strandSelect || !this.subjectSelect) {
                console.error('[DynamicDropdown] Required dropdown elements not found');
                return;
            }

            // Attach event listeners
            this.yearLevelSelect.addEventListener('change', () => this.handleYearLevelChange());
            this.strandSelect.addEventListener('change', () => this.handleStrandChange());
            this.subjectSelect.addEventListener('change', () => this.handleSubjectChange());

            this.isInitialized = true;
            console.log('[DynamicDropdown] Initialized successfully');

        } catch (error) {
            console.error('[DynamicDropdown] Initialization failed:', error);
        }
    }

    async loadSubjectsData() {
        try {
            const response = await fetch(this.apiUrl);
            
            if (!response.ok) {
                throw new Error(`API returned status ${response.status}`);
            }

            const result = await response.json();

            if (!result.success || !Array.isArray(result.data)) {
                throw new Error('Invalid API response format');
            }

            this.subjectsData = result.data;
            console.log(`[DynamicDropdown] Loaded ${this.subjectsData.length} subjects`);
            
        } catch (error) {
            console.error('[DynamicDropdown] Failed to load subjects:', error);
        }
    }

    handleYearLevelChange() {
        console.log('[DynamicDropdown] Year Level changed to:', this.yearLevelSelect.value);
        this.updateSubjectDropdown();
    }

    handleStrandChange() {
        console.log('[DynamicDropdown] Strand changed to:', this.strandSelect.value);
        this.updateSubjectDropdown();
    }

    handleSubjectChange() {
        const selectedSubjectId = this.subjectSelect.value;
        
        if (!selectedSubjectId) {
            console.log('[DynamicDropdown] Subject cleared');
            return;
        }

        // Find the selected subject
        const subject = this.subjectsData.find(s => String(s.subject_id) === String(selectedSubjectId));
        
        if (subject) {
            console.log('[DynamicDropdown] Subject selected:', subject.subject_name);
            
            // Auto-populate year level and strand
            this.yearLevelSelect.value = subject.year_level;
            this.strandSelect.value = subject.strand;
            
            console.log('[DynamicDropdown] Auto-populated Year Level:', subject.year_level, 'Strand:', subject.strand);
        }
    }

    updateSubjectDropdown() {
        const yearLevel = this.yearLevelSelect.value;
        const strand = this.strandSelect.value;

        console.log('[DynamicDropdown] Updating subject dropdown for Year Level:', yearLevel, 'Strand:', strand);

        const filteredSubjects = this.filterSubjects(yearLevel, strand);
        this.populateSubjectDropdown(filteredSubjects);
        this.validateCurrentSelection(yearLevel, strand);
    }

    filterSubjects(yearLevel, strand) {
        const cacheKey = `${yearLevel}:${strand}`;

        if (this.enableCache && this.cache.has(cacheKey)) {
            console.log('[DynamicDropdown] Using cached results for:', cacheKey);
            return this.cache.get(cacheKey);
        }

        let filtered = this.subjectsData;

        if (yearLevel) {
            filtered = filtered.filter(s => s.year_level === yearLevel);
        }

        if (strand) {
            filtered = filtered.filter(s => s.strand === strand);
        }

        filtered.sort((a, b) => a.subject_name.localeCompare(b.subject_name));

        if (this.enableCache) {
            this.cache.set(cacheKey, filtered);
        }

        console.log(`[DynamicDropdown] Found ${filtered.length} matching subjects`);
        return filtered;
    }

    populateSubjectDropdown(subjects) {
        const currentValue = this.subjectSelect.value;

        // Clear existing options except first one
        while (this.subjectSelect.options.length > 1) {
            this.subjectSelect.remove(1);
        }

        if (subjects.length === 0) {
            this.subjectSelect.disabled = true;
            const option = document.createElement('option');
            option.value = '';
            option.textContent = '-- No subjects available --';
            this.subjectSelect.appendChild(option);
            return;
        }

        this.subjectSelect.disabled = false;

        subjects.forEach(subject => {
            const option = document.createElement('option');
            option.value = subject.subject_id;
            option.textContent = subject.subject_name;
            this.subjectSelect.appendChild(option);
        });

        if (currentValue && this.subjectSelect.querySelector(`option[value="${currentValue}"]`)) {
            this.subjectSelect.value = currentValue;
        } else {
            this.subjectSelect.value = '';
        }
    }

    validateCurrentSelection(yearLevel, strand) {
        const selectedSubjectId = this.subjectSelect.value;

        if (!selectedSubjectId) {
            return;
        }

        const subject = this.subjectsData.find(s => String(s.subject_id) === String(selectedSubjectId));

        if (!subject) {
            this.subjectSelect.value = '';
            return;
        }

        const matches = (!yearLevel || subject.year_level === yearLevel) &&
                       (!strand || subject.strand === strand);

        if (!matches) {
            console.warn('[DynamicDropdown] Current subject selection does not match filters, clearing...');
            this.subjectSelect.value = '';
        }
    }

    setSubject(subjectId) {
        const subject = this.subjectsData.find(s => String(s.subject_id) === String(subjectId));
        
        if (!subject) {
            console.error('[DynamicDropdown] Subject not found:', subjectId);
            return false;
        }

        this.yearLevelSelect.value = subject.year_level;
        this.strandSelect.value = subject.strand;
        this.subjectSelect.value = subject.subject_id;
        
        this.updateSubjectDropdown();
        console.log('[DynamicDropdown] Subject set to:', subject.subject_name);
        return true;
    }

    reset() {
        this.yearLevelSelect.value = '';
        this.strandSelect.value = '';
        this.subjectSelect.value = '';
        this.updateSubjectDropdown();
        console.log('[DynamicDropdown] Reset to initial state');
    }

    destroy() {
        this.cache.clear();
        this.subjectsData = [];
        this.isInitialized = false;
        console.log('[DynamicDropdown] Destroyed');
    }
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = DynamicDropdownSystem;
}
