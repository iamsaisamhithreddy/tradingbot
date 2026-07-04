<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Pro Trade Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 p-4 md:p-8 min-h-screen font-sans">
    <div class="max-w-screen-2xl mx-auto space-y-6">
        
        <div class="flex flex-col md:flex-row justify-between items-center bg-white p-5 rounded-xl shadow-sm border border-slate-200">
            <div>
                <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight">📊 Algorithmic Dashboard</h1>
                <?php if (isset($_SESSION['current_file'])): ?>
                    <p class="text-sm text-slate-500 mt-1">Data Source: <span class="font-semibold text-blue-600"><?= htmlspecialchars($_SESSION['display_name']) ?></span></p>
                <?php endif; ?>
            </div>
            
            <?php if (isset($_SESSION['current_file'])): ?>
                <div class="flex gap-3 mt-4 md:mt-0">
                    <a href="?export=1" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2 px-5 rounded-lg text-sm transition flex items-center shadow-sm">
                        Export CSV
                    </a>
                    <form method="POST" class="m-0">
                        <input type="hidden" name="action" value="reset">
                        <button type="submit" class="bg-slate-800 hover:bg-slate-900 text-white font-semibold py-2 px-5 rounded-lg text-sm transition shadow-sm">
                            Close File
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($success_msg): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded shadow-sm"><p><?= $success_msg ?></p></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded shadow-sm"><p><?= $error_msg ?></p></div>
        <?php endif; ?>