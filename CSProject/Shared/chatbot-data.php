<?php
// ------------------------------------------------------------------
// FAQ content for the floating help-widget chatbot (Shared/chatbot-widget.php).
// Pure rule-based lookup — no external API, no cost. Every entry has:
//   'q'        => short display question shown as a quick-topic chip
//   'keywords' => words/phrases matched (case-insensitive) against
//                 whatever the user types in the chat box
//   'a'        => the answer shown (plain text + the odd <strong>/<br>
//                 is fine, this is trusted content we authored, not
//                 user input, so it's inserted with innerHTML by the
//                 widget's JS)
//
// Add new topics here — chatbot-widget.php automatically picks up
// whatever getChatbotFaqsForRole() returns, no other file needs touching.
// ------------------------------------------------------------------

function getChatbotFaqCommon(): array {
    return [
        [
            'q' => 'What is NeuroNet Quest?',
            'keywords' => ['what is this', 'what is neuronet', 'about the game', 'neuronet quest', 'about this site'],
            'a' => 'NeuroNet Quest is a classroom board game. You move along a neural-network-shaped board (Input &rarr; Hidden &rarr; Output), answer quiz questions to earn points, and can pick up power cards along the way. First to the winning score wins!',
        ],
        [
            'q' => 'Who do I contact for help?',
            'keywords' => ['contact', 'support', 'help', 'who do i ask', 'stuck', 'real person', 'human'],
            'a' => "If I can't answer your question, use the Feedback page to send a message, or reach out to your teacher/admin directly.",
        ],
    ];
}

function getChatbotFaqGuest(): array {
    return [
        [
            'q' => 'How do I log in?',
            'keywords' => ['log in', 'login', 'sign in', 'password'],
            'a' => 'Enter your registered email and password on this page. Students, teachers, and admins each have their own login page.',
        ],
        [
            'q' => 'How do I create an account?',
            'keywords' => ['sign up', 'signup', 'register', 'create account', 'new account'],
            'a' => "Use the Sign Up option on the login page and choose Student or Teacher. Student accounts work right away; Teacher accounts need admin approval before you can log in (you'll be asked for an Application Reason).",
        ],
        [
            'q' => "I signed up as a teacher but can't log in",
            'keywords' => ['pending', 'approval', 'teacher signup', "cant log in", "can't login", 'waiting'],
            'a' => "New teacher accounts start as 'pending' until an admin reviews and approves the application. Please wait for approval, or check in with your school's admin.",
        ],
    ];
}

function getChatbotFaqStudent(): array {
    return [
        [
            'q' => 'How do I join a game?',
            'keywords' => ['join', 'enter game', 'scan', 'qr', 'class code', 'code'],
            'a' => 'From your dashboard, tap Enter Game and scan your teacher\'s class QR code (or enter the class code if you\'re on the join page). If the code is valid and there\'s room, you\'ll be placed straight into the game.',
        ],
        [
            'q' => 'It says the classroom is full',
            'keywords' => ['classroom full', 'class full', "cant join", "can't join", 'full'],
            'a' => "Your teacher has set a limit on how many students can join. Ask them to raise the limit, or wait for a spot to open up.",
        ],
        [
            'q' => 'How do I answer a question?',
            'keywords' => ['answer', 'scan to answer', 'question', 'how to play', 'my turn'],
            'a' => 'After your teacher moves you on the board, a QR code appears next to your token &mdash; scan it from your game page to pull up your question and pick an answer.',
        ],
        [
            'q' => 'How does scoring work?',
            'keywords' => ['score', 'points', 'scoring', 'how many points', 'wrong answer'],
            'a' => "Correct answers earn you points (doubled if you have Double Points or Double or Nothing armed). Wrong answers cost 2 points, unless you're shielded by Double Points &mdash; your score never drops below 0.",
        ],
        [
            'q' => 'What are power cards / power nodes?',
            'keywords' => ['power', 'power card', 'power node', 'lightning', 'steal', 'double points', 'double or nothing'],
            'a' => 'Landing on a &#9889; Power node gives you a random power card: <strong>Double Points</strong> (doubles your next correct answer and shields you from the wrong-answer penalty), <strong>Steal Points</strong> (take points from another student), or <strong>Double or Nothing</strong> (double points if correct, lose double if wrong). Use them anytime from the My Powers card on your game page.',
        ],
        [
            'q' => 'What is an Epoch?',
            'keywords' => ['epoch', 'lap', 'loop', 'full loop'],
            'a' => 'An Epoch is one full loop around the board. Completing one earns you a bonus on top of your normal score.',
        ],
        [
            'q' => 'How do I win?',
            'keywords' => ['win', 'winning', 'how to win', 'winning score'],
            'a' => 'The first student to reach the winning score takes the game!',
        ],
        [
            'q' => 'The game says Class Ended',
            'keywords' => ['class ended', 'ended', 'game over', 'kicked out', 'session ended'],
            'a' => 'Your teacher reset or finished the game session. Head back to your dashboard &mdash; they may start a new one.',
        ],
        [
            'q' => 'How do I update my profile or leave feedback?',
            'keywords' => ['profile', 'feedback', 'avatar', 'picture', 'update info'],
            'a' => 'Use the Profile and Feedback cards on your dashboard to update your info or send feedback about the game.',
        ],
    ];
}

function getChatbotFaqTeacher(): array {
    return [
        [
            'q' => 'How do I create a game?',
            'keywords' => ['create game', 'new game', 'start a game', 'create a game'],
            'a' => 'From your Teacher Dashboard, click Create New Game. Choose the default shared question bank (starts running immediately) or Fully Custom questions (starts as Draft until published), then set the number of layers and a student limit.',
        ],
        [
            'q' => "Why can't students join my game?",
            'keywords' => ["cant join", "students cant join", 'draft', 'not joinable', 'publish'],
            'a' => 'Custom-question games start in Draft and aren\'t joinable yet. Go to Manage Questions, add at least 20 questions, then hit Publish &amp; Start Game to open it up.',
        ],
        [
            'q' => 'How do I add questions?',
            'keywords' => ['add question', 'questions', 'manage questions', 'create question', 'duplicate question'],
            'a' => "On the Manage Questions page, fill in a topic, difficulty layer (1-4), the question text, four answer options, and mark the correct one. Duplicate questions for the same game are blocked automatically &mdash; you'll be asked to enter a different one.",
        ],
        [
            'q' => 'How do I move a student?',
            'keywords' => ['move student', 'move a student', 'dice', 'roll'],
            'a' => 'From your game board, use Move a Student and roll the dice for them. A QR code appears for that student to scan on their own device and answer their question.',
        ],
        [
            'q' => "What's the difference between Reset and Finish?",
            'keywords' => ['reset', 'finish', 'mark finished', 'end game', 'reset game'],
            'a' => 'Both end the session for students &mdash; they\'re set Offline and see a Class Ended message. Reset also wipes scores and answers so you can start over; Finish just closes the game without wiping data.',
        ],
        [
            'q' => 'What are Epochs and Power nodes?',
            'keywords' => ['epoch', 'power node', 'lightning', 'board layout'],
            'a' => 'An Epoch is one full loop of the board (students get a bonus for completing one). &#9889; Power nodes hand out random power cards &mdash; Double Points, Steal Points, or Double or Nothing &mdash; to whoever lands on them.',
        ],
        [
            'q' => 'How is question difficulty decided?',
            'keywords' => ['difficulty', 'layer', 'hard', 'easy', 'hidden layer'],
            'a' => "Difficulty follows how deep a student is on the board &mdash; tiles closer to Input pull easier questions, tiles near Output pull harder ones from that student's layer.",
        ],
        [
            'q' => 'Can I delete a game?',
            'keywords' => ['delete game', 'remove game', 'delete'],
            'a' => 'Yes &mdash; Finished or still-Draft games can be deleted from the Enter Game list. A currently Running game needs to be finished first.',
        ],
        [
            'q' => 'Where do I manage my profile or feedback?',
            'keywords' => ['profile', 'feedback'],
            'a' => 'Your Teacher Dashboard has Profile and Feedback cards below the main game actions.',
        ],
    ];
}

function getChatbotFaqAdmin(): array {
    return [
        [
            'q' => 'How do I add a new user?',
            'keywords' => ['add user', 'create account', 'new account', 'add account'],
            'a' => 'Go to Manage User Account &rarr; Add Account. Fill in their name, email, and role &mdash; the account is created active immediately with the default password <strong>12345678</strong> (have them change it after logging in).',
        ],
        [
            'q' => 'How do I approve a teacher signup?',
            'keywords' => ['approve teacher', 'pending teacher', 'teacher application', 'approve'],
            'a' => 'Open the Approve Teacher tab to see pending applications, including their Application Reason. Click Approve to activate the account, or Reject to deactivate it.',
        ],
        [
            'q' => 'How do I edit or delete a user?',
            'keywords' => ['edit user', 'delete user', 'remove account', 'update account'],
            'a' => 'From Manage User Account, use Edit to change a user\'s name, email, phone, role, status, or reset their password &mdash; or Delete to permanently remove a non-admin account.',
        ],
        [
            'q' => 'How do I send an announcement?',
            'keywords' => ['announcement', 'notify', 'notification', 'broadcast'],
            'a' => 'Go to the Notification tab, choose Broadcast New Announcement, pick your audience (All / Teachers / Students), and write a title and content.',
        ],
        [
            'q' => 'How do I handle feedback tickets?',
            'keywords' => ['feedback', 'resolve feedback', 'ticket'],
            'a' => "The Feedback Management tab lists submitted feedback. Once you've handled one, click Mark Resolved.",
        ],
    ];
}

// Merges the role-specific list with the common list every role shares.
function getChatbotFaqsForRole(string $role): array {
    switch ($role) {
        case 'student':
            $roleFaqs = getChatbotFaqStudent();
            break;
        case 'teacher':
            $roleFaqs = getChatbotFaqTeacher();
            break;
        case 'admin':
            $roleFaqs = getChatbotFaqAdmin();
            break;
        case 'guest':
        default:
            $roleFaqs = getChatbotFaqGuest();
            break;
    }

    return array_merge($roleFaqs, getChatbotFaqCommon());
}
