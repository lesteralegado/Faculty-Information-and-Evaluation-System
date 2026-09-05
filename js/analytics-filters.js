/**
 * Analytics Advanced Filters - Bi-directional Dynamic Filtering
 * 
 * Features:
 * - Real-time filter updates without page reloads
 * - Bi-directional filtering: Subject ↔ Teacher
 * - Synchronized with School Year and Semester filters
 * - Smooth AJAX updates with loading states
 */

class AnalyticsFilterSystem {
    constructor(config = {}) {
        this.apiUrl = config.apiUrl || '/capstone/api/get_filter_options.php';
        this.yearSelectId = config.yearSelectId || 'yearFilter';
        this.semesterSelectId = config.semesterSelectId || 'semesterFilter';
        this.subjectSelectId = config.subjectSelectId || 'subjectFilter';
        this.teacherSelectId = config.teacherSelectId || 'teacherFilter';
        this.formId = config.formId || 'filterForm';
        
        this.yearSelect = null;
        this.semesterSelect = null;
        this.subjectSelect = null;
        this.teacherSelect = null;
        this.form = null;
        
        this.isInitialized = false;
        this.cache = new Map();
        this.debounceTimer = null;
        
        this.init();
    }

    init() {
        try {
            console.log('[AnalyticsFilters] Initializing...');
            
            // Get DOM elements
            this.form = document.getElementById(this.formId);
            this.yearSelect = document.getElementById(this.yearSelectId);
            this.semesterSelect = document.getElementById(this.semesterSelectId);
            this.subjectSelect = document.getElementById(this.subjectSelectId);
            this.teacherSelect = document.getElementById(this.teacherSelectId);
            
            // Verify all elements exist
            if (!this.form || !this.yearSelect || !this.semesterSelect || !this.subjectSelect || !this.teacherSelect) {
                console.warn('[AnalyticsFilters] Some filter elements not found', {
                    form: !!this.form,
                    year: !!this.yearSelect,
                    semester: !!this.semesterSelect,
                    subject: !!this.subjectSelect,
                    teacher: !!this.teacherSelect
                });
                return;
            }
            
            // Attach event listeners
            this.yearSelect.addEventListener('change', () => this.handleYearChange());
            this.semesterSelect.addEventListener('change', () => this.handleSemesterChange());
            this.subjectSelect.addEventListener('change', () => this.handleSubjectChange());
            this.teacherSelect.addEventListener('change', () => this.handleTeacherChange());
            
            this.isInitialized = true;
            console.log('[AnalyticsFilters] Initialized successfully');
            
        } catch (error) {
            console.error('[AnalyticsFilters] Initialization failed:', error);
        }
    }

    /**
     * Get current filter values
     */
    getCurrentFilters() {
        return {
            year: this.yearSelect?.value || 'all',
            semester: this.semesterSelect?.value || 'all',
            subject: this.subjectSelect?.value || 'all',
            teacher: this.teacherSelect?.value || 'all'
        };
    }

    /**
     * Create cache key for AJAX results
     */
    getCacheKey(type, year, semester, subject, teacher) {
        return `${type}_${year}_${semester}_${subject}_${teacher}`;
    }

    /**
     * Handle Year filter change
     */
    handleYearChange() {
        console.log('[AnalyticsFilters] Year changed to:', this.yearSelect.value);
        const filters = this.getCurrentFilters();
        
        // Reset Subject and Teacher to 'all' when year changes
        this.subjectSelect.value = 'all';
        this.teacherSelect.value = 'all';
        
        // Refresh both dropdowns
        this.updateSubjectDropdown();
        this.updateTeacherDropdown();
    }

    /**
     * Handle Semester filter change
     */
    handleSemesterChange() {
        console.log('[AnalyticsFilters] Semester changed to:', this.semesterSelect.value);
        const filters = this.getCurrentFilters();
        
        // Reset Subject and Teacher to 'all' when semester changes
        this.subjectSelect.value = 'all';
        this.teacherSelect.value = 'all';
        
        // Refresh both dropdowns
        this.updateSubjectDropdown();
        this.updateTeacherDropdown();
    }

    /**
     * Handle Subject filter change
     */
    async handleSubjectChange() {
        const subjectId = this.subjectSelect.value;
        console.log('[AnalyticsFilters] Subject changed to:', subjectId);
        
        // Update teacher dropdown based on selected subject
        await this.updateTeacherDropdown();
        
        // Submit form to apply the filter
        this.submitForm();
    }

    /**
     * Handle Teacher filter change
     */
    async handleTeacherChange() {
        const teacherId = this.teacherSelect.value;
        console.log('[AnalyticsFilters] Teacher changed to:', teacherId);
        
        // Update subject dropdown based on selected teacher
        await this.updateSubjectDropdown();
        
        // Submit form to apply the filter
        this.submitForm();
    }

    /**
     * Update Subject dropdown based on selected Teacher (and Year/Semester)
     */
    async updateSubjectDropdown() {
        const filters = this.getCurrentFilters();
        const currentSubject = this.subjectSelect.value;
        
        // Determine which API call to make
        let type, id;
        
        if (filters.teacher !== 'all') {
            // Teacher is selected: get subjects for this teacher
            type = 'subjects';
            id = filters.teacher;
        } else {
            // No teacher selected: get all subjects for year/semester
            type = 'subjects_for_year';
            id = null;
        }
        
        const cacheKey = this.getCacheKey(type, filters.year, filters.semester, id, null);
        
        // Check cache first
        if (this.cache.has(cacheKey)) {
            const subjects = this.cache.get(cacheKey);
            this.populateSubjectDropdown(subjects, currentSubject);
            return;
        }
        
        try {
            // Add loading indicator
            this.subjectSelect.disabled = true;
            
            let url = `${this.apiUrl}?type=${encodeURIComponent(type)}&school_year=${encodeURIComponent(filters.year)}&semester=${encodeURIComponent(filters.semester)}`;
            
            if (id !== null) {
                url += `&teacher_id=${encodeURIComponent(id)}`;
            }
            
            console.log('[AnalyticsFilters] Fetching subjects:', url);
            
            const response = await fetch(url);
            
            if (!response.ok) {
                throw new Error(`API returned status ${response.status}`);
            }
            
            const subjects = await response.json();
            
            // Cache the result
            this.cache.set(cacheKey, subjects);
            
            this.populateSubjectDropdown(subjects, currentSubject);
            
        } catch (error) {
            console.error('[AnalyticsFilters] Failed to fetch subjects:', error);
        } finally {
            this.subjectSelect.disabled = false;
        }
    }

    /**
     * Update Teacher dropdown based on selected Subject (and Year/Semester)
     */
    async updateTeacherDropdown() {
        const filters = this.getCurrentFilters();
        const currentTeacher = this.teacherSelect.value;
        
        // Determine which API call to make
        let type, id;
        
        if (filters.subject !== 'all') {
            // Subject is selected: get teachers for this subject
            type = 'teachers';
            id = filters.subject;
        } else {
            // No subject selected: get all teachers for year/semester
            type = 'teachers_for_year';
            id = null;
        }
        
        const cacheKey = this.getCacheKey(type, filters.year, filters.semester, null, id);
        
        // Check cache first
        if (this.cache.has(cacheKey)) {
            const teachers = this.cache.get(cacheKey);
            this.populateTeacherDropdown(teachers, currentTeacher);
            return;
        }
        
        try {
            // Add loading indicator
            this.teacherSelect.disabled = true;
            
            let url = `${this.apiUrl}?type=${encodeURIComponent(type)}&school_year=${encodeURIComponent(filters.year)}&semester=${encodeURIComponent(filters.semester)}`;
            
            if (id !== null) {
                url += `&subject_id=${encodeURIComponent(id)}`;
            }
            
            console.log('[AnalyticsFilters] Fetching teachers:', url);
            
            const response = await fetch(url);
            
            if (!response.ok) {
                throw new Error(`API returned status ${response.status}`);
            }
            
            const teachers = await response.json();
            
            // Cache the result
            this.cache.set(cacheKey, teachers);
            
            this.populateTeacherDropdown(teachers, currentTeacher);
            
        } catch (error) {
            console.error('[AnalyticsFilters] Failed to fetch teachers:', error);
        } finally {
            this.teacherSelect.disabled = false;
        }
    }

    /**
     * Populate Subject dropdown with options
     */
    populateSubjectDropdown(subjects, currentValue) {
        // Store current value
        const previousValue = this.subjectSelect.value;
        
        // Clear all options except "All Subjects"
        this.subjectSelect.innerHTML = '<option value="all">All Subjects</option>';
        
        if (Array.isArray(subjects) && subjects.length > 0) {
            subjects.forEach(subject => {
                const option = document.createElement('option');
                option.value = subject.id;
                option.textContent = subject.name;
                this.subjectSelect.appendChild(option);
            });
            
            // Restore previous value if it still exists, otherwise keep 'all'
            if (currentValue && currentValue !== 'all') {
                const optionExists = subjects.some(s => s.id == currentValue);
                if (optionExists) {
                    this.subjectSelect.value = currentValue;
                } else {
                    this.subjectSelect.value = 'all';
                }
            } else {
                this.subjectSelect.value = 'all';
            }
        } else {
            this.subjectSelect.value = 'all';
        }
        
        console.log('[AnalyticsFilters] Subject dropdown updated, selected:', this.subjectSelect.value);
    }

    /**
     * Populate Teacher dropdown with options
     */
    populateTeacherDropdown(teachers, currentValue) {
        // Store current value
        const previousValue = this.teacherSelect.value;
        
        // Clear all options except "All Teachers"
        this.teacherSelect.innerHTML = '<option value="all">All Teachers</option>';
        
        if (Array.isArray(teachers) && teachers.length > 0) {
            teachers.forEach(teacher => {
                const option = document.createElement('option');
                option.value = teacher.id;
                option.textContent = teacher.name;
                this.teacherSelect.appendChild(option);
            });
            
            // Restore previous value if it still exists, otherwise keep 'all'
            if (currentValue && currentValue !== 'all') {
                const optionExists = teachers.some(t => t.id == currentValue);
                if (optionExists) {
                    this.teacherSelect.value = currentValue;
                } else {
                    this.teacherSelect.value = 'all';
                }
            } else {
                this.teacherSelect.value = 'all';
            }
        } else {
            this.teacherSelect.value = 'all';
        }
        
        console.log('[AnalyticsFilters] Teacher dropdown updated, selected:', this.teacherSelect.value);
    }

    /**
     * Submit the form with current filter values
     */
    submitForm() {
        if (this.form) {
            console.log('[AnalyticsFilters] Submitting form with filters:', this.getCurrentFilters());
            this.form.submit();
        }
    }

    /**
     * Clear cache (useful for manual refresh)
     */
    clearCache() {
        this.cache.clear();
        console.log('[AnalyticsFilters] Cache cleared');
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    window.analyticsFilterSystem = new AnalyticsFilterSystem();
});
