<div class="bg-white rounded-xl shadow-sm p-16 mt-10 max-w-2xl mx-auto text-center border border-slate-200">
    <svg class="w-20 h-20 text-blue-500 mx-auto mb-6 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path></svg>
    <h2 class="text-3xl font-bold mb-3 text-slate-800">Upload Trading CSV</h2>
    <p class="text-slate-500 mb-8 text-lg">Upload your exported trade history to calculate edge, visualize drawdowns, and track strategies.</p>
    
    <form method="POST" enctype="multipart/form-data" class="flex flex-col items-center">
        <input type="hidden" name="action" value="upload">
        <input type="file" name="csv_file" accept=".csv" required class="block w-full text-sm text-slate-500 file:mr-4 file:py-3 file:px-6 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 mb-6 cursor-pointer border border-slate-200 rounded-full p-1"/>
        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-12 rounded-full shadow-lg transition w-full md:w-auto text-lg">
            Build Dashboard
        </button>
    </form>
</div>