// This file shows the JavaScript code that needs to be added to subject_management.php
// Add this code BEFORE the closing </script> tag (around line 1615)

        // Initialize dynamic dropdown system for Add Modal
        let addSubjectDropdown;
        document.getElementById('addSubjectModal').addEventListener('show.bs.modal', function() {
            setTimeout(() => {
                if (!addSubjectDropdown || !addSubjectDropdown.isInitialized) {
                    addSubjectDropdown = new DynamicDropdownSystem({
                        apiUrl: '/capstone/api/get_subjects_data.php',
                        yearLevelSelectId: 'add_year_level',
                        strandSelectId: 'add_strand',
                        subjectSelectId: 'add_subject_name'
                    });
                }
            }, 100);
        });

        // Initialize dynamic dropdown system for Edit Modal
        let editSubjectDropdown;
        document.getElementById('editSubjectModal').addEventListener('show.bs.modal', function() {
            setTimeout(() => {
                if (!editSubjectDropdown || !editSubjectDropdown.isInitialized) {
                    editSubjectDropdown = new DynamicDropdownSystem({
                        apiUrl: '/capstone/api/get_subjects_data.php',
                        yearLevelSelectId: 'edit_year_level',
                        strandSelectId: 'edit_strand',
                        subjectSelectId: 'edit_subject_name'
                    });
                }
            }, 100);
        });

        document.getElementById('addSubjectForm').addEventListener('submit', function(e) {
            const subjectId = document.getElementById('add_subject_name').value.trim();
            const yearLevel = document.getElementById('add_year_level').value;
            const strand = document.getElementById('add_strand').value;
            
            if (!subjectId || !yearLevel || !strand) {
                e.preventDefault();
                showModernAlert('warning', 'Validation Required', 'Subject Name, Year Level, and Strand are required.');
                return false;
            }
        });

        document.getElementById('editSubjectForm').addEventListener('submit', function(e) {
            const subjectId = document.getElementById('edit_subject_name').value.trim();
            const yearLevel = document.getElementById('edit_year_level').value;
            const strand = document.getElementById('edit_strand').value;
            
            if (!subjectId || !yearLevel || !strand) {
                e.preventDefault();
                showModernAlert('warning', 'Validation Required', 'Subject Name, Year Level, and Strand are required.');
                return false;
            }
        });
