// Improved Math Editor implementation for assessment system
document.addEventListener('DOMContentLoaded', function() {
    // Only run on the assessment taking page
    if (document.getElementById('assessmentForm')) {
        initMathInputToggle();
    }
});

// Initialize toggle buttons for each short answer field
function initMathInputToggle() {
    // Find short answer containers
    const shortAnswerContainers = document.querySelectorAll('.single-answer-container');
    
    shortAnswerContainers.forEach(container => {
        // Get the original text input
        const originalInput = container.querySelector('input[type="text"]');
        if (!originalInput) return;
        
        // Create toggle button
        const toggleButton = document.createElement('button');
        toggleButton.type = 'button';
        toggleButton.className = 'btn btn-sm btn-outline-secondary math-toggle';
        toggleButton.innerHTML = '<i class="fas fa-calculator"></i> Math Editor';
        toggleButton.title = 'Toggle math equation editor';
        
        // Insert toggle before the input group
        const inputGroup = originalInput.closest('.input-group');
        inputGroup.parentNode.insertBefore(toggleButton, inputGroup);
        
        // Add event listener
        toggleButton.addEventListener('click', function() {
            toggleMathEditor(container, originalInput, this);
        });
    });
}

// Toggle between regular text input and math editor
function toggleMathEditor(container, originalInput, toggleButton) {
    const hasEditor = container.querySelector('.math-editor-wrapper');
    
    if (hasEditor) {
        // Get the current LaTeX content before switching back
        const MQ = MathQuill.getInterface(2);
        const mathField = MQ.MathField(hasEditor.querySelector('.math-field'));
        originalInput.value = mathField.latex();
        
        // Switch back to text input
        originalInput.closest('.input-group').style.display = '';
        hasEditor.style.display = 'none';
        toggleButton.innerHTML = '<i class="fas fa-calculator"></i> Math Editor';
        toggleButton.classList.remove('active');
    } else {
        // Create and initialize math editor
        const mathEditorWrapper = document.createElement('div');
        mathEditorWrapper.className = 'math-editor-wrapper';
        
        const mathEditorContainer = document.createElement('div');
        mathEditorContainer.className = 'math-field';
        mathEditorWrapper.appendChild(mathEditorContainer);
        
        // Add status indicator for visual feedback
        const statusIndicator = document.createElement('div');
        statusIndicator.className = 'editor-status';
        statusIndicator.innerHTML = '<i class="fas fa-pen"></i> Editing...';
        mathEditorWrapper.appendChild(statusIndicator);
        
        const toolbar = createMathToolbar(mathEditorContainer);
        mathEditorWrapper.appendChild(toolbar);
        
        // Insert the math editor before the input group
        const inputGroup = originalInput.closest('.input-group');
        inputGroup.parentNode.insertBefore(mathEditorWrapper, inputGroup);
        
        // Hide the original input group
        inputGroup.style.display = 'none';
        
        // Initialize MathQuill with improved options
        const MQ = MathQuill.getInterface(2);
        const mathField = MQ.MathField(mathEditorContainer, {
            spaceBehavesLikeTab: true,
            // Expanded autoCommands to recognize more mathematical expressions
            autoCommands: 'pi theta phi omega alpha beta gamma delta sum prod int sqrt nthroot',
            // Expanded autoOperatorNames to include more functions
            autoOperatorNames: 'sin cos tan csc sec cot arcsin arccos arctan sinh cosh tanh ln log exp',
            // Keep these settings for proper equation structure
            supSubsRequireOperand: false, // Changed to false to allow easier superscript/subscript entry
            charsThatBreakOutOfSupSub: '+-=<>',
            // Allow backslash for LaTeX commands
            preventBackslash: false,
            handlers: {
                edit: function() {
                    // Update the original input value with LaTeX
                    originalInput.value = mathField.latex();
                    
                    // Update status indicator to show active editing
                    statusIndicator.innerHTML = '<i class="fas fa-pen text-success"></i> Editing...';
                    
                    // Trigger any event listeners on the original input
                    const event = new Event('input', { bubbles: true });
                    originalInput.dispatchEvent(event);
                    
                    // Reset status indicator after a delay
                    clearTimeout(statusIndicator.timeout);
                    statusIndicator.timeout = setTimeout(() => {
                        statusIndicator.innerHTML = '<i class="fas fa-check text-success"></i> Ready';
                    }, 1000);
                },
                enter: function() {
                    // Allow enter to create new lines in multi-line equations
                    mathField.write('\\\\');
                    return false;
                },
                upOutOf: function(mathField) {
                    // Improve navigation with arrow keys
                    mathField.bubble('upOutOf');
                    return false;
                },
                downOutOf: function(mathField) {
                    // Improve navigation with arrow keys
                    mathField.bubble('downOutOf');
                    return false;
                }
            }
        });
        
        // Add clear button for quick reset
        const clearButton = document.createElement('button');
        clearButton.type = 'button';
        clearButton.className = 'btn btn-sm btn-outline-danger clear-math-btn';
        clearButton.innerHTML = '<i class="fas fa-eraser"></i> Clear';
        clearButton.addEventListener('click', function() {
            mathField.latex('');
            mathField.focus();
        });
        
        const editorControls = document.createElement('div');
        editorControls.className = 'editor-controls';
        editorControls.appendChild(clearButton);
        mathEditorWrapper.insertBefore(editorControls, toolbar);
        
        // If there's already a value in the input, set it in the math field
        if (originalInput.value) {
            mathField.latex(originalInput.value);
        }
        
        toggleButton.innerHTML = '<i class="fas fa-font"></i> Text Input';
        toggleButton.classList.add('active');
        
        // Focus the math editor
        mathField.focus();
        
        // Add keyboard shortcuts help button
        const helpButton = document.createElement('button');
        helpButton.type = 'button';
        helpButton.className = 'btn btn-sm btn-outline-info help-btn';
        helpButton.innerHTML = '<i class="fas fa-question-circle"></i> Help';
        helpButton.addEventListener('click', function() {
            showMathEditorHelp(mathEditorWrapper);
        });
        editorControls.appendChild(helpButton);
    }
}

// Show help modal with keyboard shortcuts
function showMathEditorHelp(container) {
    // Create modal if it doesn't exist
    if (!document.getElementById('mathEditorHelpModal')) {
        const modal = document.createElement('div');
        modal.id = 'mathEditorHelpModal';
        modal.className = 'math-help-modal';
        modal.innerHTML = `
            <div class="math-help-content">
                <div class="math-help-header">
                    <h4>Math Editor Keyboard Shortcuts</h4>
                    <button class="btn-close">&times;</button>
                </div>
                <div class="math-help-body">
                    <div class="shortcut-section">
                        <h5>Basic Shortcuts</h5>
                        <ul>
                            <li><kbd>^</kbd> or <kbd>Shift+6</kbd> - Exponent/superscript</li>
                            <li><kbd>_</kbd> or <kbd>Shift+Minus</kbd> - Subscript</li>
                            <li><kbd>/</kbd> - Fraction (wraps selection)</li>
                            <li><kbd>Shift+Up/Down</kbd> - Navigate between superscript/subscript</li>
                            <li><kbd>Shift+Left/Right</kbd> - Navigate out of current element</li>
                        </ul>
                    </div>
                    <div class="shortcut-section">
                        <h5>Special Commands</h5>
                        <ul>
                            <li><kbd>\\sqrt</kbd> then <kbd>Space</kbd> - Square root</li>
                            <li><kbd>\\pi</kbd> then <kbd>Space</kbd> - π symbol</li>
                            <li><kbd>\\theta</kbd> then <kbd>Space</kbd> - θ symbol</li>
                            <li><kbd>\\sum</kbd> then <kbd>Space</kbd> - Summation Σ</li>
                            <li><kbd>\\int</kbd> then <kbd>Space</kbd> - Integral ∫</li>
                            <li><kbd>\\frac</kbd> then <kbd>Space</kbd> - Fraction template</li>
                        </ul>
                    </div>
                    <div class="shortcut-section">
                        <h5>Editing Tips</h5>
                        <ul>
                            <li>Use arrow keys to navigate through your equation</li>
                            <li>Press <kbd>Backspace</kbd> to delete characters</li>
                            <li>Select part of equation and type to replace it</li>
                            <li>Click anywhere in the equation to position cursor</li>
                            <li>Type <kbd>\\</kbd> followed by a command name to insert special symbols</li>
                        </ul>
                    </div>
                </div>
                <div class="math-help-footer">
                    <button class="btn btn-primary close-help">Got it!</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        
        // Add event listeners to close buttons
        const closeButtons = modal.querySelectorAll('.btn-close, .close-help');
        closeButtons.forEach(button => {
            button.addEventListener('click', function() {
                modal.style.display = 'none';
            });
        });
        
        // Close when clicking outside the modal
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                modal.style.display = 'none';
            }
        });
    }
    
    // Show the modal
    const modal = document.getElementById('mathEditorHelpModal');
    modal.style.display = 'flex';
}

// Create the toolbar with math and chemistry tabs
function createMathToolbar(mathFieldElement) {
    const toolbar = document.createElement('div');
    toolbar.className = 'math-toolbar';
    
    // Create category tabs
    const tabContainer = document.createElement('div');
    tabContainer.className = 'toolbar-tabs';
    
    const categories = [
        { id: 'basic', name: 'Basic' },
        { id: 'fractions', name: 'Fractions' },
        { id: 'chemistry', name: 'Chemistry' },
        { id: 'advanced', name: 'Advanced' }
    ];
    
    // Create tab buttons
    categories.forEach((category, index) => {
        const tabBtn = document.createElement('button');
        tabBtn.type = 'button';
        tabBtn.className = 'toolbar-tab' + (index === 0 ? ' active' : '');
        tabBtn.dataset.category = category.id;
        tabBtn.textContent = category.name;
        tabContainer.appendChild(tabBtn);
    });
    
    toolbar.appendChild(tabContainer);
    
    // Create symbol arrays for each category
    const basicSymbols = [
        { display: '+', latex: '+' },
        { display: '-', latex: '-' },
        { display: '×', latex: '\\times' },
        { display: '÷', latex: '\\div' },
        { display: '=', latex: '=' },
        { display: 'x²', latex: 'x^2' },
        { display: 'x³', latex: 'x^3' },
        { display: 'x^n', latex: 'x^n' },
        { display: '√', latex: '\\sqrt{}' },
        { display: 'π', latex: '\\pi' },
        { display: '()', latex: '\\left(\\right)' },
        { display: '[]', latex: '\\left[\\right]' }
    ];
    
    const fractionSymbols = [
        { display: 'a/b', latex: '\\frac{}{}' },        // Generic fraction
        { display: 'c a/b', latex: '{}\\frac{}{}' },    // Mixed fraction template
        { display: 'a²/b', latex: '\\frac{^2}{}' },     // Fraction with exponent in numerator
        { display: 'a/b²', latex: '\\frac{}{^2}' }      // Fraction with exponent in denominator
    ];
    
    const chemistrySymbols = [
        { display: 'H₂O', latex: 'H_2O' },
        { display: '→', latex: '\\rightarrow' },
        { display: '⇌', latex: '\\rightleftharpoons' },
        { display: '(g)', latex: '\\text{(g)}' },
        { display: '(l)', latex: '\\text{(l)}' },
        { display: '(s)', latex: '\\text{(s)}' },
        { display: '(aq)', latex: '\\text{(aq)}' },
        { display: 'Δ', latex: '\\Delta' },
        { display: 'pH', latex: '\\text{pH}' },
        { display: 'X↑', latex: 'X\\uparrow' },        // Gas evolution
        { display: 'X↓', latex: 'X\\downarrow' }       // Precipitation
    ];
    
    const advancedSymbols = [
        { display: '∑', latex: '\\sum_{}^{}' },
        { display: '∫', latex: '\\int_{}^{}' },
        { display: '∞', latex: '\\infty' },
        { display: '±', latex: '\\pm' },
        { display: '∛', latex: '\\sqrt[3]{}' },
        { display: '∂', latex: '\\partial' },
        { display: 'θ', latex: '\\theta' },
        { display: 'α', latex: '\\alpha' },
        { display: 'β', latex: '\\beta' },
        { display: 'γ', latex: '\\gamma' },
        { display: 'λ', latex: '\\lambda' },
        { display: 'μ', latex: '\\mu' }
    ];
    
    // Create and append category containers
    const symbolCategories = {
        'basic': createSymbolButtonsContainer(basicSymbols, mathFieldElement, 'symbol-container active'),
        'fractions': createSymbolButtonsContainer(fractionSymbols, mathFieldElement, 'symbol-container'),
        'chemistry': createSymbolButtonsContainer(chemistrySymbols, mathFieldElement, 'symbol-container'),
        'advanced': createSymbolButtonsContainer(advancedSymbols, mathFieldElement, 'symbol-container')
    };
    
    Object.values(symbolCategories).forEach(container => {
        toolbar.appendChild(container);
    });
    
    // Add tab switching functionality
    tabContainer.querySelectorAll('.toolbar-tab').forEach(tab => {
        tab.addEventListener('click', function() {
            // Update active tab
            tabContainer.querySelectorAll('.toolbar-tab').forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            
            // Show selected symbol container
            Object.keys(symbolCategories).forEach(category => {
                symbolCategories[category].classList.remove('active');
                if (category === this.dataset.category) {
                    symbolCategories[category].classList.add('active');
                }
            });
        });
    });
    
    return toolbar;
}

function createSymbolButtonsContainer(symbols, mathFieldElement, className) {
    const container = document.createElement('div');
    container.className = className;
    
    symbols.forEach(symbol => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'symbol-btn';
        button.textContent = symbol.display;
        button.title = symbol.latex;
        
        // Add visual feedback on hover
        button.addEventListener('mouseenter', function() {
            this.classList.add('symbol-hover');
        });
        
        button.addEventListener('mouseleave', function() {
            this.classList.remove('symbol-hover');
        });
        
        // Add visual feedback on click
        button.addEventListener('click', function() {
            const mathField = MathQuill.getInterface(2).MathField(mathFieldElement);
            
            // Add visual feedback
            this.classList.add('symbol-active');
            setTimeout(() => {
                this.classList.remove('symbol-active');
            }, 300);
            
            // Special handling for different types of symbols
            if (symbol.latex.includes('{}')) {
                // For templates with empty slots, place cursor in first slot
                let latexWithCursor = symbol.latex.replace('{}', '{\\cursor}');
                
                // If there are multiple empty slots, just place cursor in the first one
                if (latexWithCursor.includes('{}')) {
                    latexWithCursor = latexWithCursor.replace('{}', '{}');
                }
                
                mathField.write(latexWithCursor);
            } else {
                // For regular symbols, just write them
                mathField.write(symbol.latex);
            }
            
            mathField.focus();
        });
        
        container.appendChild(button);
    });
    
    return container;
}

// Add CSS styles for the improved math editor
function addMathEditorStyles() {
    const styleSheet = document.createElement("style");
    styleSheet.textContent = `
    /* Math editor styles */
    .math-toggle {
        margin-bottom: 10px;
        background: #f8f9fa;
        border-color: #ddd;
        transition: all 0.2s ease;
    }

    .math-toggle.active {
        background-color: #000;
        color: #ffd700;
        border-color: #ffd700;
    }

    .math-editor-wrapper {
        margin-bottom: 15px;
        border: 1px solid #ddd;
        border-radius: 6px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        background: white;
    }

    .math-field {
        min-height: 60px;
        padding: 15px;
        border-bottom: 1px solid #eee;
        background: white;
        cursor: text;
        transition: background-color 0.2s ease;
    }

    .math-field:focus-within {
        background-color: #f8fcff;
        box-shadow: inset 0 0 0 2px rgba(0, 123, 255, 0.25);
    }

    .editor-status {
        padding: 5px 10px;
        font-size: 0.8rem;
        color: #666;
        background: #f8f9fa;
        border-bottom: 1px solid #eee;
    }

    .editor-controls {
        display: flex;
        justify-content: flex-end;
        padding: 8px;
        background: #f8f9fa;
        border-bottom: 1px solid #eee;
        gap: 8px;
    }

    .clear-math-btn, .help-btn {
        font-size: 0.8rem;
        padding: 0.25rem 0.5rem;
    }

    .math-toolbar {
        display: flex;
        flex-direction: column;
        background: #f8f9fa;
    }

    .toolbar-tabs {
        display: flex;
        overflow-x: auto;
        border-bottom: 1px solid #ddd;
        background: #eee;
    }

    .toolbar-tab {
        padding: 8px 16px;
        background: none;
        border: none;
        cursor: pointer;
        border-bottom: 2px solid transparent;
        white-space: nowrap;
        transition: all 0.2s ease;
    }

    .toolbar-tab:hover {
        background-color: rgba(0, 0, 0, 0.05);
    }

    .toolbar-tab.active {
        border-bottom: 2px solid #ffd700;
        font-weight: 500;
        background-color: white;
    }

    .symbol-container {
        display: none;
        flex-wrap: wrap;
        padding: 10px;
        gap: 5px;
    }

    .symbol-container.active {
        display: flex;
    }

    .symbol-btn {
        margin: 2px;
        padding: 8px 12px;
        background: white;
        border: 1px solid #ddd;
        border-radius: 4px;
        cursor: pointer;
        font-size: 16px;
        transition: all 0.15s ease;
        min-width: 40px;
        text-align: center;
    }

    .symbol-btn:hover, .symbol-hover {
        background: #f0f0f0;
        border-color: #bbb;
        transform: translateY(-1px);
        box-shadow: 0 2px 3px rgba(0, 0, 0, 0.1);
    }

    .symbol-active {
        background-color: #ffd700 !important;
        border-color: #e6c200 !important;
        color: #000 !important;
        transform: translateY(1px) !important;
        box-shadow: none !important;
    }

    /* MathQuill custom styling */
    .mq-editable-field {
        min-width: 100%;
        border: none !important;
        box-shadow: none !important;
    }

    .mq-math-mode {
        font-size: 18px;
    }

    .mq-cursor {
        border-left: 2px solid #ffd700 !important;
        margin-left: -1px !important;
        margin-right: -1px !important;
        animation: cursor-blink 1s infinite;
    }

    .mq-editable-field.mq-focused {
        box-shadow: none !important;
    }

    .mq-editable-field .mq-selection {
        background: #d4e9ff !important;
    }

    @keyframes cursor-blink {
        0%, 50% { opacity: 1; }
        51%, 100% { opacity: 0; }
    }

    /* Help modal styling */
    .math-help-modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
        z-index: 1000;
        justify-content: center;
        align-items: center;
    }

    .math-help-content {
        background-color: white;
        border-radius: 8px;
        width: 90%;
        max-width: 600px;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
    }

    .math-help-header {
        padding: 15px;
        border-bottom: 1px solid #eee;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .math-help-header h4 {
        margin: 0;
        color: #333;
    }

    .btn-close {
        background: transparent;
        border: none;
        font-size: 1.5rem;
        cursor: pointer;
        padding: 0;
        line-height: 1;
    }

    .math-help-body {
        padding: 15px;
        max-height: 60vh;
        overflow-y: auto;
    }

    .shortcut-section {
        margin-bottom: 20px;
    }

    .shortcut-section h5 {
        margin-top: 0;
        margin-bottom: 10px;
        color: #444;
        border-bottom: 1px solid #eee;
        padding-bottom: 5px;
    }

    .shortcut-section ul {
        margin: 0;
        padding-left: 20px;
    }

    .shortcut-section li {
        margin-bottom: 5px;
    }

    kbd {
        background-color: #f7f7f7;
        border: 1px solid #ccc;
        border-radius: 3px;
        box-shadow: 0 1px 0 rgba(0, 0, 0, 0.2);
        color: #333;
        display: inline-block;
        font-size: 0.85em;
        font-family: monospace;
        line-height: 1;
        padding: 2px 4px;
        white-space: nowrap;
    }

    .math-help-footer {
        padding: 15px;
        border-top: 1px solid #eee;
        text-align: right;
    }

    .btn-primary {
        background-color: #000;
        color: #ffd700;
        border: none;
        padding: 8px 16px;
        border-radius: 4px;
        cursor: pointer;
    }

    .btn-primary:hover {
        background-color: #333;
    }

    /* Mobile optimization */
    @media (max-width: 576px) {
        .toolbar-tabs {
            justify-content: flex-start;
        }
        
        .symbol-btn {
            padding: 10px;
            min-width: 36px;
            font-size: 14px;
        }
        
        .math-help-content {
            width: 95%;
        }
        
        .shortcut-section li {
            margin-bottom: 10px;
        }
    }
    `;
    document.head.appendChild(styleSheet);
}

// Add the styles when DOM is loaded
document.addEventListener('DOMContentLoaded', addMathEditorStyles);