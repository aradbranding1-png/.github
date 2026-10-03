<?php
/**
 * LMS schema: categories, files, courses, lessons, paths, growth, enrollments, progress,
 * exams, question bank, exercises, evaluations, certificates, needs, assignments, rules.
 */
return [
    'description' => 'جداول آموزشی: دوره، درس، مسیر، رشد، ثبت‌نام، پیشرفت، آزمون، بانک سؤال، تمرین، گواهی',
    'up' => function (PDO $db): void {
        $T = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $sql = [];

        $sql[] = "CREATE TABLE IF NOT EXISTS categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            parent_id INT UNSIGNED NULL,
            segment VARCHAR(20) NULL,
            name VARCHAR(150) NOT NULL,
            description VARCHAR(500) NULL,
            icon VARCHAR(40) NOT NULL DEFAULT 'book-open',
            color VARCHAR(20) NOT NULL DEFAULT '#6366f1',
            sort INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            KEY idx_cat_parent (parent_id),
            KEY idx_cat_segment (segment)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS files (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL UNIQUE,
            title VARCHAR(200) NULL,
            original_name VARCHAR(200) NOT NULL,
            stored_path VARCHAR(255) NOT NULL,
            mime VARCHAR(120) NOT NULL,
            ext VARCHAR(10) NOT NULL,
            size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            kind VARCHAR(20) NOT NULL,
            folder VARCHAR(100) NULL,
            viewable TINYINT(1) NOT NULL DEFAULT 1,
            downloadable TINYINT(1) NOT NULL DEFAULT 0,
            is_library TINYINT(1) NOT NULL DEFAULT 1,
            views INT UNSIGNED NOT NULL DEFAULT 0,
            downloads INT UNSIGNED NOT NULL DEFAULT 0,
            uploaded_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            deleted_at DATETIME NULL,
            KEY idx_files_kind (kind, deleted_at)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS courses (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200) NOT NULL,
            code VARCHAR(50) NULL,
            summary VARCHAR(500) NULL,
            description MEDIUMTEXT NULL,
            syllabus MEDIUMTEXT NULL,
            image_file_id INT UNSIGNED NULL,
            category_id INT UNSIGNED NULL,
            target_segment VARCHAR(20) NULL,
            group_id INT UNSIGNED NULL,
            level_id INT UNSIGNED NULL,
            instructor_id INT UNSIGNED NULL,
            training_type VARCHAR(20) NOT NULL DEFAULT 'optional',
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            is_sequential TINYINT(1) NOT NULL DEFAULT 1,
            self_enroll TINYINT(1) NOT NULL DEFAULT 1,
            duration_minutes INT UNSIGNED NULL,
            pass_score DECIMAL(5,2) NOT NULL DEFAULT 60,
            has_certificate TINYINT(1) NOT NULL DEFAULT 0,
            certificate_validity_months INT UNSIGNED NULL,
            publish_at DATETIME NULL,
            expire_at DATETIME NULL,
            published_at DATETIME NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            deleted_at DATETIME NULL,
            KEY idx_courses_status (status, deleted_at),
            KEY idx_courses_cat (category_id),
            KEY idx_courses_seg (target_segment),
            KEY idx_courses_instructor (instructor_id)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS course_prerequisites (
            course_id INT UNSIGNED NOT NULL,
            prerequisite_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (course_id, prerequisite_id)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS lessons (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            course_id INT UNSIGNED NOT NULL,
            section_title VARCHAR(150) NULL,
            title VARCHAR(200) NOT NULL,
            content_type VARCHAR(20) NOT NULL DEFAULT 'text',
            body MEDIUMTEXT NULL,
            media_file_id INT UNSIGNED NULL,
            link_url VARCHAR(500) NULL,
            duration_minutes INT UNSIGNED NULL,
            prerequisite_lesson_id INT UNSIGNED NULL,
            is_preview TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'published',
            sort INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            deleted_at DATETIME NULL,
            KEY idx_lessons_course (course_id, sort)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS lesson_files (
            lesson_id INT UNSIGNED NOT NULL,
            file_id INT UNSIGNED NOT NULL,
            sort INT NOT NULL DEFAULT 0,
            PRIMARY KEY (lesson_id, file_id)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS learning_paths (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200) NOT NULL,
            description TEXT NULL,
            target_segment VARCHAR(20) NULL,
            group_id INT UNSIGNED NULL,
            color VARCHAR(20) NOT NULL DEFAULT '#6366f1',
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            deleted_at DATETIME NULL
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS path_steps (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            path_id INT UNSIGNED NOT NULL,
            course_id INT UNSIGNED NOT NULL,
            title VARCHAR(200) NULL,
            sort INT NOT NULL DEFAULT 0,
            min_progress TINYINT UNSIGNED NOT NULL DEFAULT 100,
            min_score DECIMAL(5,2) NULL,
            require_exercises TINYINT(1) NOT NULL DEFAULT 0,
            require_evaluation TINYINT(1) NOT NULL DEFAULT 0,
            KEY idx_ps_path (path_id, sort)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS path_enrollments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            path_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            current_step INT NOT NULL DEFAULT 1,
            status VARCHAR(20) NOT NULL DEFAULT 'in_progress',
            progress_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
            assigned_by INT UNSIGNED NULL,
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_pe (path_id, user_id),
            KEY idx_pe_user (user_id)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS growth_stages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            group_id INT UNSIGNED NOT NULL,
            name VARCHAR(150) NOT NULL,
            description TEXT NULL,
            sort INT NOT NULL DEFAULT 1,
            color VARCHAR(20) NOT NULL DEFAULT '#10b981',
            icon VARCHAR(40) NOT NULL DEFAULT 'mountain',
            req_course_ids VARCHAR(500) NULL,
            req_min_avg_score DECIMAL(5,2) NULL,
            req_min_progress TINYINT UNSIGNED NULL,
            req_exercises INT UNSIGNED NULL,
            req_evaluation TINYINT(1) NOT NULL DEFAULT 0,
            auto_promote TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            KEY idx_gs_group (group_id, sort)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS user_growth (
            user_id INT UNSIGNED NOT NULL,
            group_id INT UNSIGNED NOT NULL,
            stage_id INT UNSIGNED NOT NULL,
            achieved_at DATETIME NOT NULL,
            promoted_by INT UNSIGNED NULL,
            PRIMARY KEY (user_id, group_id)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS growth_history (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            group_id INT UNSIGNED NOT NULL,
            stage_id INT UNSIGNED NOT NULL,
            promoted_by INT UNSIGNED NULL,
            note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            KEY idx_gh_user (user_id)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS assignments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            course_id INT UNSIGNED NULL,
            path_id INT UNSIGNED NULL,
            target_type VARCHAR(20) NOT NULL,
            target_id INT UNSIGNED NOT NULL,
            training_type VARCHAR(20) NOT NULL DEFAULT 'mandatory',
            due_at DATETIME NULL,
            note VARCHAR(255) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            enrolled_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            KEY idx_asg_target (target_type, target_id),
            KEY idx_asg_course (course_id)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS assignment_rules (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            conditions TEXT NOT NULL,
            match_type VARCHAR(5) NOT NULL DEFAULT 'all',
            course_id INT UNSIGNED NULL,
            path_id INT UNSIGNED NULL,
            training_type VARCHAR(20) NOT NULL DEFAULT 'mandatory',
            due_days INT UNSIGNED NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_run_at DATETIME NULL,
            last_run_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS enrollments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            course_id INT UNSIGNED NOT NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'self',
            assignment_id INT UNSIGNED NULL,
            rule_id INT UNSIGNED NULL,
            path_id INT UNSIGNED NULL,
            training_type VARCHAR(20) NOT NULL DEFAULT 'optional',
            status VARCHAR(20) NOT NULL DEFAULT 'not_started',
            progress_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
            score DECIMAL(5,2) NULL,
            last_lesson_id INT UNSIGNED NULL,
            due_at DATETIME NULL,
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            last_activity_at DATETIME NULL,
            assigned_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_enroll (user_id, course_id),
            KEY idx_enroll_course (course_id, status),
            KEY idx_enroll_status (status),
            KEY idx_enroll_due (due_at)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS lesson_progress (
            user_id INT UNSIGNED NOT NULL,
            lesson_id INT UNSIGNED NOT NULL,
            course_id INT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'in_progress',
            percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
            position_sec INT UNSIGNED NOT NULL DEFAULT 0,
            time_spent_sec INT UNSIGNED NOT NULL DEFAULT 0,
            views INT UNSIGNED NOT NULL DEFAULT 0,
            first_viewed_at DATETIME NULL,
            last_viewed_at DATETIME NULL,
            completed_at DATETIME NULL,
            PRIMARY KEY (user_id, lesson_id),
            KEY idx_lp_course (user_id, course_id),
            KEY idx_lp_lesson (lesson_id)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS question_categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            parent_id INT UNSIGNED NULL,
            name VARCHAR(150) NOT NULL,
            created_at DATETIME NOT NULL
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS questions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            category_id INT UNSIGNED NULL,
            type VARCHAR(20) NOT NULL,
            difficulty TINYINT UNSIGNED NOT NULL DEFAULT 2,
            level_id INT UNSIGNED NULL,
            text TEXT NOT NULL,
            explanation TEXT NULL,
            score DECIMAL(6,2) NOT NULL DEFAULT 1,
            tags VARCHAR(255) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            deleted_at DATETIME NULL,
            KEY idx_q_cat (category_id, is_active),
            KEY idx_q_type (type)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS question_options (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            question_id INT UNSIGNED NOT NULL,
            text TEXT NOT NULL,
            is_correct TINYINT(1) NOT NULL DEFAULT 0,
            sort INT NOT NULL DEFAULT 0,
            KEY idx_qo_q (question_id),
            CONSTRAINT fk_qo_q FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS exams (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200) NOT NULL,
            description TEXT NULL,
            course_id INT UNSIGNED NULL,
            lesson_id INT UNSIGNED NULL,
            is_required TINYINT(1) NOT NULL DEFAULT 1,
            time_limit_minutes INT UNSIGNED NULL,
            pass_score DECIMAL(5,2) NOT NULL DEFAULT 60,
            max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
            shuffle_questions TINYINT(1) NOT NULL DEFAULT 1,
            shuffle_options TINYINT(1) NOT NULL DEFAULT 1,
            random_count INT UNSIGNED NULL,
            random_category_id INT UNSIGNED NULL,
            show_answers TINYINT(1) NOT NULL DEFAULT 1,
            available_from DATETIME NULL,
            available_until DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            deleted_at DATETIME NULL,
            KEY idx_exams_course (course_id)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS exam_questions (
            exam_id INT UNSIGNED NOT NULL,
            question_id INT UNSIGNED NOT NULL,
            score DECIMAL(6,2) NULL,
            sort INT NOT NULL DEFAULT 0,
            PRIMARY KEY (exam_id, question_id)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS exam_attempts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            exam_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            attempt_no INT UNSIGNED NOT NULL DEFAULT 1,
            question_ids TEXT NOT NULL,
            option_order TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'in_progress',
            score DECIMAL(8,2) NULL,
            max_score DECIMAL(8,2) NULL,
            percent DECIMAL(5,2) NULL,
            passed TINYINT(1) NULL,
            started_at DATETIME NOT NULL,
            deadline_at DATETIME NULL,
            submitted_at DATETIME NULL,
            graded_at DATETIME NULL,
            graded_by INT UNSIGNED NULL,
            KEY idx_att_user (user_id, exam_id),
            KEY idx_att_exam (exam_id, status)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS attempt_answers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            attempt_id INT UNSIGNED NOT NULL,
            question_id INT UNSIGNED NOT NULL,
            answer TEXT NULL,
            is_correct TINYINT(1) NULL,
            score DECIMAL(6,2) NULL,
            max_score DECIMAL(6,2) NULL,
            feedback TEXT NULL,
            reviewed_by INT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            UNIQUE KEY uq_aa (attempt_id, question_id),
            KEY idx_aa_q (question_id)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS exercises (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            course_id INT UNSIGNED NOT NULL,
            lesson_id INT UNSIGNED NULL,
            title VARCHAR(200) NOT NULL,
            instructions MEDIUMTEXT NULL,
            formats VARCHAR(100) NOT NULL DEFAULT 'text,file,image,video',
            max_score DECIMAL(6,2) NOT NULL DEFAULT 100,
            pass_score DECIMAL(6,2) NOT NULL DEFAULT 60,
            is_required TINYINT(1) NOT NULL DEFAULT 1,
            due_days INT UNSIGNED NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'published',
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            deleted_at DATETIME NULL,
            KEY idx_ex_course (course_id)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS exercise_submissions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            exercise_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            text_answer MEDIUMTEXT NULL,
            file_id INT UNSIGNED NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'submitted',
            score DECIMAL(6,2) NULL,
            feedback TEXT NULL,
            reviewed_by INT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            KEY idx_sub_ex (exercise_id, status),
            KEY idx_sub_user (user_id)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS practical_evaluations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            course_id INT UNSIGNED NULL,
            stage_id INT UNSIGNED NULL,
            title VARCHAR(200) NOT NULL,
            criteria TEXT NULL,
            score DECIMAL(6,2) NULL,
            max_score DECIMAL(6,2) NOT NULL DEFAULT 100,
            passed TINYINT(1) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            evaluator_id INT UNSIGNED NULL,
            evaluated_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_pe_user (user_id),
            KEY idx_pe_course (course_id)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS certificates (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(30) NOT NULL UNIQUE,
            user_id INT UNSIGNED NOT NULL,
            course_id INT UNSIGNED NOT NULL,
            user_name VARCHAR(200) NOT NULL,
            course_title VARCHAR(200) NOT NULL,
            instructor_name VARCHAR(200) NULL,
            score DECIMAL(5,2) NULL,
            issued_at DATETIME NOT NULL,
            expires_at DATETIME NULL,
            issued_by INT UNSIGNED NULL,
            revoked_at DATETIME NULL,
            revoke_reason VARCHAR(255) NULL,
            KEY idx_cert_user (user_id),
            KEY idx_cert_course (course_id)
        )$T";

        $sql[] = "CREATE TABLE IF NOT EXISTS training_needs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            title VARCHAR(200) NOT NULL,
            description TEXT NULL,
            category_id INT UNSIGNED NULL,
            priority VARCHAR(10) NOT NULL DEFAULT 'medium',
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            source VARCHAR(20) NOT NULL DEFAULT 'manager',
            resolved_course_id INT UNSIGNED NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            KEY idx_needs_user (user_id, status)
        )$T";

        foreach ($sql as $s) $db->exec($s);
    },
];
